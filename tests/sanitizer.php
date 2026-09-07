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
 * text must not.
 */

require __DIR__ . '/../vendor/autoload.php';

use FriendsOfRedaxo\VTrans\VTransConnection;
use FriendsOfRedaxo\VTrans\VTransHtmlFilter;
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
$roundTrip = static function (string $html, bool $sanitizeEnabled = true, ?string $allowExtra = null, ?callable $provider = null): string {
    $filter = new VTransHtmlFilter();
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

echo "\n" . (0 === $failures ? "All checks passed.\n" : $failures . " check(s) failed.\n");

exit(0 === $failures ? 0 : 1);
