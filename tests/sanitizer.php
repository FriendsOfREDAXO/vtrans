<?php

/**
 * Standalone checks for the interplay of VTransHtmlFilter and VTransSanitizer.
 *
 * The addon has no test suite; this script needs no REDAXO and no database and
 * is run by hand:
 *
 *     php tests/sanitizer.php
 *
 * It covers what is easy to break and expensive to notice: excluded regions
 * must survive sanitisation with their event handlers, ordinary translated
 * text must not; attribute values travel through the provider and back into
 * their tags, and data-/aria- attributes survive. It also checks VTransPrompt,
 * whose HTML rule keeps the placeholders the filter relies on.
 */

require __DIR__ . '/../vendor/autoload.php';

use FriendsOfRedaxo\VTrans\VTransConnection;
use FriendsOfRedaxo\VTrans\VTransHtmlFilter;
use FriendsOfRedaxo\VTrans\VTransPrompt;
use FriendsOfRedaxo\VTrans\VTransSanitizer;

$failures = 0;
$assert = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "  ok   $name\n";
        return;
    }
    ++$failures;
    echo "  FAIL $name" . ('' !== $detail ? "\n       $detail" : '') . "\n";
};

/**
 * Full round trip: mask, sanitise the provider's answer, restore.
 * The fake provider simply echoes the payload back unchanged.
 */
$roundTrip = static function (string $html, bool $sanitizeEnabled = true, ?string $allowExtra = null, ?callable $provider = null, array $translateAttributes = []): string {
    $filter = new VTransHtmlFilter($translateAttributes);
    $payload = $filter->prepare($html);
    if (null !== $provider) {
        $payload = $provider($payload);
    }

    return $filter->restore(VTransSanitizer::sanitizeWith($payload, $sanitizeEnabled, $allowExtra));
};

echo "1) Excluded regions keep their on* attributes while the sanitiser is active\n";

$cases = [
    'data-vtrans-exclude wrapper' => '<div data-vtrans-exclude><button onclick="track()">Klick</button></div>',
    'notranslate on the element itself' => '<button class="notranslate" onclick="track()">Klick</button>',
    'notranslate wrapper' => '<div class="notranslate"><button onclick="track()">Klick</button></div>',
    'translate="no" on the element itself' => '<button translate="no" onclick="track()">Klick</button>',
    'excluded <script>' => '<div data-vtrans-exclude><script>var a = 1 < 2;</script></div>',
];

foreach ($cases as $name => $html) {
    $out = $roundTrip($html);
    $assert($name, $out === $html, 'got: ' . $out);
}

echo "\n2) Ordinary translated text is still sanitised\n";

$out = $roundTrip('<p onclick="steal()">Hallo <a href="javascript:steal()">Welt</a></p>');
$assert('on* attribute removed', !str_contains($out, 'onclick'), 'got: ' . $out);
$assert('javascript: URL removed', !str_contains($out, 'javascript:'), 'got: ' . $out);
$assert('text kept', str_contains($out, 'Hallo'), 'got: ' . $out);

// A script the provider adds to its answer is not author markup: the filter never
// masked it, so it must not survive.
$out = $roundTrip(
    '<p>Hallo Welt</p>',
    true,
    null,
    static fn (string $payload): string => $payload . '<script>steal()</script>'
);
$assert('script injected by the provider removed', !str_contains($out, 'steal()'), 'got: ' . $out);

$mixed = $roundTrip('<div data-vtrans-exclude><button onclick="ok()">Menü</button></div><p onclick="bad()">Hallo Welt</p>');
$assert('excluded and sanitised part in one document', str_contains($mixed, 'onclick="ok()"') && !str_contains($mixed, 'bad()'), 'got: ' . $mixed);

echo "\n3) sanitize_html = false passes raw HTML through unchanged\n";

$raw = '<p onclick="anything()">Hallo</p><script>anything()</script>';
$out = $roundTrip($raw, false);
$assert('document unchanged', $out === $raw, 'got: ' . $out);
$assert('sanitizeWith(false) is a no-op', VTransSanitizer::sanitizeWith($raw, false) === $raw);

// Without sanitisation nothing balances the provider's tags before restore(), so a
// single dropped closing placeholder must not take the rest of the document with it.
$list = '<ul>'
    . implode('', array_map(
        static fn (int $i): string => '<li><span class="notranslate">SKU-' . $i . '</span> Artikel ' . $i . '</li>',
        range(1, 3)
    ))
    . '</ul><p>Fussnote</p>';

$out = $roundTrip(
    $list,
    false,
    null,
    // An LLM provider normalises the self-closing placeholders into pairs — and drops
    // the closing tag of the first one. Without the guard in the phase 1 pattern, that
    // one missing tag lets the match run to the closing tag of the *next* placeholder.
    static function (string $payload): string {
        $paired = preg_replace(
            '/<vtrans-ph id="(\d+)"\/>/',
            '<vtrans-ph id="$1"></vtrans-ph>',
            $payload
        ) ?? $payload;

        return preg_replace('/<vtrans-ph id="0"><\/vtrans-ph>/', '<vtrans-ph id="0">', $paired, 1) ?? $paired;
    }
);

foreach ([1, 2, 3] as $i) {
    $assert('dropped closing tag keeps SKU-' . $i, str_contains($out, 'SKU-' . $i), 'got: ' . $out);
    $assert('dropped closing tag keeps Artikel ' . $i, str_contains($out, 'Artikel ' . $i), 'got: ' . $out);
}
$assert('dropped closing tag keeps the tail of the document', str_contains($out, 'Fussnote'), 'got: ' . $out);

echo "\n4) A connection without an explicit setting stays on the safe default\n";

$connection = new VTransConnection();
$assert('sanitize_html defaults to true', $connection->isSanitizeHtml());
$assert('sanitize_allow_extra defaults to null', null === $connection->getSanitizeAllowExtra());
$assert(
    'the default settings sanitise',
    VTransSanitizer::sanitizeWith($raw, $connection->isSanitizeHtml(), $connection->getSanitizeAllowExtra()) !== $raw
);

echo "\n5) sanitize_allow_extra widens the allowlist selectively\n";

$out = $roundTrip('<p onclick="ok()">Hallo</p>', true, 'onclick');
$assert('allowed attribute survives', str_contains($out, 'onclick="ok()"'), 'got: ' . $out);

$out = $roundTrip('<p onclick="ok()" onmouseover="bad()">Hallo</p>', true, 'onclick');
$assert('non-listed attribute still removed', !str_contains($out, 'onmouseover'), 'got: ' . $out);

$out = $roundTrip('<iframe src="https://example.org/"></iframe>', true, '<iframe>');
$assert('allowed element survives', str_contains($out, '<iframe'), 'got: ' . $out);

$out = $roundTrip('<iframe src="https://example.org/"></iframe>', true, 'onclick');
$assert('element not listed is still removed', !str_contains($out, '<iframe'), 'got: ' . $out);

$parsed = VTransSanitizer::parseAllowExtra('onclick, <iframe>; data-action  !!bogus!!');
$assert('parser splits elements and attributes', ['iframe'] === $parsed['elements'] && ['onclick', 'data-action'] === $parsed['attributes'], print_r($parsed, true));

echo "\n6) VTransPrompt: a configured prompt replaces the default, placeholders resolve, the format rule stays\n";

$labels = ['de' => 'German (DE)', 'en-gb' => 'English – British (EN-GB)'];

$out = VTransPrompt::build('', 'DE', 'EN-GB', 'text', '', [], $labels);
$assert('default names both languages', str_contains($out, 'Translate from German to English – British.'), 'got: ' . $out);

$out = VTransPrompt::build('Übersetze nach {target_lang_name} ({target_lang}).', 'DE', 'EN-GB', 'text', '', [], $labels);
$assert('custom prompt replaces the default', !str_contains($out, 'professional translation engine'), 'got: ' . $out);
$assert('placeholders resolve', str_starts_with($out, 'Übersetze nach English – British (EN-GB).'), 'got: ' . $out);

$out = VTransPrompt::build('Nach {target_lang_name}.', 'DE', 'EN-GB', 'html', '', [], $labels);
$assert('HTML rule survives a custom prompt', str_contains($out, '<vtrans-ph>'), 'got: ' . $out);

$out = VTransPrompt::build('Von {source_lang_name} nach {target_lang}.', null, 'FR', 'text', '', [], $labels);
$assert('missing source and unknown label fall back', str_contains($out, 'Von the source language (detect it) nach FR.'), 'got: ' . $out);

$out = VTransPrompt::build('Nach {target_lang}.', 'DE', 'EN-GB', 'text', "Nach {target_lang}.\n\nHotel website", ['Be brief'], $labels);
$assert('connection prompt is not repeated as context', 1 === substr_count($out, 'Nach EN-GB.') && str_contains($out, "Context:\nHotel website"), 'got: ' . $out);
$assert('instructions use real line breaks', str_contains($out, "Additional instructions:\n- Be brief") && !str_contains($out, '\\n'), 'got: ' . $out);

$assert('prompt without target language is flagged', !VTransPrompt::mentionsTargetLanguage('Be formal.') && VTransPrompt::mentionsTargetLanguage('Into {target_lang_name}.'));

echo "\n7) Slider: data-/aria- attributes survive, alt and title are translated in the same request\n";

// A provider like DeepL in HTML mode: translates text, leaves attribute values alone.
$dictionary = [
    'Rote Jacke' => 'Red jacket',
    'Warm & "gemütlich"' => 'Warm & "cosy"',
    'Herbstkollektion' => 'Autumn collection',
    'Jetzt entdecken' => 'Discover now',
    'Zurück' => 'Previous',
    'Suchen' => 'Search',
    'Karussell' => 'Carousel',
];
$payloads = [];
$deepl = static function (string $payload) use ($dictionary, &$payloads): string {
    $payloads[] = $payload;
    $encoded = [];
    foreach ($dictionary as $de => $en) {
        $encoded[htmlspecialchars($de, ENT_NOQUOTES)] = htmlspecialchars($en, ENT_NOQUOTES);
    }

    return strtr($payload, $encoded);
};
$attrs = VTransHtmlFilter::DEFAULT_TRANSLATE_ATTRIBUTES;

$slider = '<div id="carousel-7" class="carousel slide" data-bs-ride="carousel" aria-roledescription="Karussell">'
    . '<div class="carousel-indicators">'
    . '<button type="button" data-bs-target="#carousel-7" data-bs-slide-to="0" class="active" aria-current="true" aria-label="1"></button>'
    . '<button type="button" data-bs-target="#carousel-7" data-bs-slide-to="1" aria-label="2"></button>'
    . '</div>'
    . '<div class="carousel-inner"><div class="carousel-item active">'
    . '<img loading="lazy" class="d-block w-100" alt="Rote Jacke" title="Warm &amp; &quot;gemütlich&quot;" src="/media/jacke.webp" srcset="/media/320/jacke.webp 320w, /media/640/jacke.webp 640w">'
    . '<div class="carousel-caption"><div class="h2">Herbstkollektion</div><a class="btn btn-primary" href="/herbst/">Jetzt entdecken</a></div>'
    . '</div></div>'
    . '<button class="carousel-control-prev" type="button" data-bs-target="#carousel-7" data-bs-slide="prev">'
    . '<span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Zurück</span></button>'
    . '</div>';

$payloads = [];
$out = $roundTrip($slider, true, null, $deepl, $attrs);
$assert('one request for text and attributes', 1 === count($payloads), 'requests: ' . count($payloads));
$assert('source alt text not left in the tag', !str_contains($payloads[0] ?? '', 'alt="Rote Jacke"'), 'payload: ' . ($payloads[0] ?? ''));
foreach (['data-bs-ride="carousel"', 'data-bs-target="#carousel-7"', 'data-bs-slide-to="1"', 'data-bs-slide="prev"', 'aria-current="true"', 'aria-hidden="true"', 'aria-label="1"'] as $kept) {
    $assert('kept ' . $kept, str_contains($out, $kept), 'got: ' . $out);
}
$assert('alt translated', str_contains($out, 'alt="Red jacket"'), 'got: ' . $out);
$assert('title translated and escaped', str_contains($out, 'title="Warm &amp; &quot;cosy&quot;"'), 'got: ' . $out);
$assert('aria-roledescription translated', str_contains($out, 'aria-roledescription="Carousel"'), 'got: ' . $out);
$assert('caption and hidden text translated', str_contains($out, 'Autumn collection') && str_contains($out, 'Previous'), 'got: ' . $out);
$assert('src and srcset untouched', str_contains($out, 'src="/media/jacke.webp"') && str_contains($out, '/media/640/jacke.webp 640w'), 'got: ' . $out);
$assert('no carrier or token left', !str_contains($out, 'vtrans-attr') && !str_contains($out, '__vtrans_attr_'), 'got: ' . $out);

$out = $roundTrip($slider, false, null, $deepl, $attrs);
$assert('works with sanitisation off', str_contains($out, 'alt="Red jacket"') && !str_contains($out, 'vtrans-attr'), 'got: ' . $out);

echo "\n8) Translated attribute values cannot break out of their attribute\n";

$hostile = static fn (string $payload): string => preg_replace(
    '/(<vtrans-attr id="0">).*?(<\/vtrans-attr>)/s',
    '$1Jacket" onmouseover="steal()" x="<b>bold</b><script>steal()</script>$2',
    $payload
) ?? $payload;
foreach ([true, false] as $enabled) {
    $out = $roundTrip('<p><img src="/a.jpg" alt="Jacke"> Text</p>', $enabled, null, $hostile, $attrs);
    $label = $enabled ? ' (sanitised)' : ' (unsanitised)';
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $out);
    $img = $dom->getElementsByTagName('img')->item(0);
    $assert('no new attribute' . $label, null !== $img && 2 === $img->attributes->length && !$img->hasAttribute('onmouseover'), 'got: ' . $out);
    $assert('value is plain text' . $label, !str_contains($out, '<b>') && !str_contains($out, '<script'), 'got: ' . $out);
}

echo "\n9) Only real text is extracted\n";

$extracted = static function (string $html) use ($attrs): int {
    $filter = new VTransHtmlFilter($attrs);
    $filter->prepare($html);

    return $filter->getAttributeCount();
};
$skip = [
    'empty value' => '<img alt="">',
    'number' => '<button aria-label="3"></button>',
    'URL' => '<a title="https://example.org/a">x</a>',
    'path' => '<a title="/produkte/jacke">x</a>',
    'file name' => '<img alt="IMG_1234.jpg">',
    'markup' => '<span title="<b>x</b>">x</span>',
    'not in the list' => '<div data-bs-title="Hallo">x</div>',
    'value on a text input' => '<input type="text" value="Max Mustermann">',
    'value on an option' => '<option value="rot">Rot</option>',
    'notranslate void element' => '<img class="lazy notranslate" alt="Marke">',
    'translate="no" void element' => '<img translate="no" alt="Marke">',
    'data-vtrans-exclude void element' => '<img data-vtrans-exclude alt="Marke">',
    'inside notranslate' => '<div class="notranslate"><img alt="Marke"></div>',
    'inside data-vtrans-exclude' => '<div data-vtrans-exclude><a title="Marke">x</a></div>',
];
foreach ($skip as $name => $html) {
    $assert('skips ' . $name, 0 === $extracted($html));
}
$take = [
    'value on a submit button' => '<input type="submit" value="Absenden">',
    'placeholder' => '<input type="search" placeholder="Suchen">',
    'unquoted value' => '<img alt=Jacke>',
    'single-quoted value' => "<abbr title='Gesellschaft mit beschränkter Haftung'>GmbH</abbr>",
    'class merely containing notranslate' => '<img class="notranslate-hint" alt="Jacke">',
];
foreach ($take as $name => $html) {
    $assert('takes ' . $name, 1 === $extracted($html));
}

$out = $roundTrip('<p><a href="/x" title="Mehr &gt; weniger" class="a>b">Suchen</a></p>', true, null, $deepl, $attrs);
$assert('">" inside an attribute value does not end the tag', str_contains($out, 'title="Mehr &gt; weniger"') && str_contains($out, 'Search'), 'got: ' . $out);

$out = $roundTrip('<p><img src="/a.jpg" alt="Rote Jacke"> Suchen</p>', true, null, static fn (string $p): string => preg_replace('/\s*<p><vtrans-attr.*$/s', '', $p) ?? $p, $attrs);
$assert('carrier dropped by the provider: original value kept', str_contains($out, 'alt="Rote Jacke"') && !str_contains($out, '__vtrans_attr_'), 'got: ' . $out);

$filter = new VTransHtmlFilter([]);
$assert('empty list disables extraction', $filter->prepare('<img alt="Rote Jacke">') === '<img alt="Rote Jacke">');

$assert('empty list spec falls back to the defaults', VTransHtmlFilter::DEFAULT_TRANSLATE_ATTRIBUTES === VTransHtmlFilter::parseAttributeList('  '));
$assert('list spec is parsed', ['alt', 'data-bs-title'] === VTransHtmlFilter::parseAttributeList('ALT, data-bs-title; !!x'));

echo "\n10) The sanitiser keeps data-/aria- attributes and still blocks scripting\n";

$out = VTransSanitizer::sanitizeWith('<a href="/x" data-bs-toggle="tooltip" data-bs-title="Hi" aria-expanded="false" role="button" onclick="bad()">x</a>', true);
$assert('data-* kept', str_contains($out, 'data-bs-toggle="tooltip"') && str_contains($out, 'data-bs-title="Hi"'), 'got: ' . $out);
$assert('aria-* and role kept', str_contains($out, 'aria-expanded="false"') && str_contains($out, 'role="button"'), 'got: ' . $out);
$assert('on* still removed', !str_contains($out, 'onclick'), 'got: ' . $out);

$out = VTransSanitizer::sanitizeWith('<a href="data:text/html,<script>x</script>" data-x="1">a</a><a href="javascript:bad()">b</a>', true);
$assert('data: and javascript: URLs still removed', !str_contains($out, 'data:text') && !str_contains($out, 'javascript:'), 'got: ' . $out);

$long = str_repeat('<p>Lorem ipsum dolor sit amet.</p>', 1000) . '<p>ENDE</p>';
$assert('input over 20 KB is not truncated', str_contains(VTransSanitizer::sanitizeWith($long, true), 'ENDE'));

echo "\n" . (0 === $failures ? "All checks passed.\n" : $failures . " check(s) failed.\n");

exit(0 === $failures ? 0 : 1);
