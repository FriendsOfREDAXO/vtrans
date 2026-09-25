<?php

namespace FriendsOfRedaxo\VTrans;

/**
 * Provider-agnostic HTML pre-/post-processor.
 *
 * Before a translation request is sent to any provider this filter:
 * 1. Removes entire blocks that should never be sent (script, style, code,
 *    svg and elements with `data-vtrans-exclude`).
 * 2. Replaces elements marked with `translate="no"` or the CSS class
 *    `notranslate` — opening tag included — with compact placeholders.
 *
 * 3. Moves translatable attribute values (alt, title, aria-label, …) out of
 *    their tags into a block appended to the payload, so providers that leave
 *    attributes alone in HTML mode translate them in the same request.
 *
 * After the translated text comes back, all placeholders are resolved back
 * to their original content and the translated attribute values are written
 * back into their tags.
 */
class VTransHtmlFilter
{
	/** Tags whose content is never useful for translation. */
	private const AUTO_EXCLUDE_TAGS = ['script', 'style', 'code', 'svg'];

	/** Placeholder tag name — intentionally an unknown HTML element so APIs pass it through. */
	private const PH_TAG = 'vtrans-ph';

	/**
	 * Attributes translated when a connection does not name its own list.
	 * `value` is only ever taken from `<input type="button|submit|reset">`;
	 * on every other element it is data, not text.
	 */
	public const DEFAULT_TRANSLATE_ATTRIBUTES = [
		'alt',
		'title',
		'placeholder',
		'aria-label',
		'aria-description',
		'aria-roledescription',
		'aria-placeholder',
		'value',
	];

	/** Carrier element for an extracted attribute value in the payload. */
	private const ATTR_TAG = 'vtrans-attr';

	/** Stand-in written into the attribute while its value travels in the carrier. */
	private const ATTR_TOKEN = '__vtrans_attr_%d__';

	/** @var array<int, string> id => original content */
	private array $map = [];

	/** Running placeholder counter. */
	private int $nextId = 0;

	/** @var list<string> lower-case attribute names to translate */
	private array $translateAttributes;

	/** @var array<int, string> id => original, entity-decoded attribute value */
	private array $attrValues = [];

	/**
	 * @param list<string> $translateAttributes attribute names to translate; empty disables it
	 */
	public function __construct(array $translateAttributes = [])
	{
		$this->translateAttributes = array_values(array_unique(array_map('strtolower', $translateAttributes)));
	}

	/**
	 * Parse the free-text attribute list of a connection.
	 *
	 * Entries are separated by whitespace, commas or semicolons; anything that
	 * is not a valid attribute name is ignored. An empty list means "use the
	 * defaults", not "translate nothing" — switching the feature off is a
	 * separate setting.
	 *
	 * @return list<string>
	 */
	public static function parseAttributeList(?string $spec): array
	{
		$attributes = [];
		foreach (preg_split('/[\s,;]+/', strtolower(trim((string) $spec))) ?: [] as $token) {
			if (1 === preg_match('/^[a-z_:][a-z0-9_:.-]*$/', $token)) {
				$attributes[$token] = true;
			}
		}

		return [] !== $attributes ? array_keys($attributes) : self::DEFAULT_TRANSLATE_ATTRIBUTES;
	}

	/**
	 * Pre-process HTML before sending it to a translation provider.
	 *
	 * Returns the cleaned HTML with placeholders. Call {@see restore()} on
	 * the translated result to put the original fragments back.
	 */
	public function prepare(string $html): string
	{
		$this->map = [];
		$this->nextId = 0;
		$this->attrValues = [];

		// 0. Protect vtrans-chunk placeholders emitted by VTransHtmlChunker.
		//    When translateHtml() translates a shell that contains chunk placeholders
		//    alongside real text, this prevents providers from mangling them.
		$html = preg_replace_callback(
			'/<vtrans-chunk\b[^>]*\/>/si',
			fn(array $m) => $this->placeholder($m[0]),
			$html,
		) ?? $html;

		// 1. Remove auto-excluded tags (script, style, code, svg) with their full content.
		$html = $this->replaceAutoExcludeTags($html);

		// 2. Remove elements marked with data-vtrans-exclude (with their full content).
		$html = $this->replaceMarkedElements($html, 'data-vtrans-exclude');

		// 3. Protect translate="no" and class="notranslate" elements.
		$html = $this->replaceNoTranslateElements($html);

		// 4. Move translatable attribute values into a carrier block. This runs last,
		//    so everything masked above is already a placeholder and out of reach.
		if ([] !== $this->translateAttributes) {
			$html = $this->extractAttributes($html);
		}

		return $html;
	}

	/**
	 * Restore all placeholders in the translated text with the original content.
	 *
	 * Stored fragments can themselves contain placeholders: prepare() masks
	 * script/style/code/svg first, so an element replaced later (data-vtrans-exclude,
	 * translate="no", notranslate) may hold placeholders created in that earlier step.
	 * A single pass would leave those nested placeholders as literal text.
	 *
	 * Restoring therefore happens in two phases with deliberately different patterns:
	 * one tolerant pass over the provider's answer, then repeated strict passes over
	 * the fragments this method re-inserted itself, until nothing changes any more.
	 * The iteration limit is a safety brake against malformed input; in practice a
	 * single extra pass suffices.
	 */
	public function restore(string $html): string
	{
		if ([] !== $this->attrValues) {
			$html = $this->restoreAttributes($html);
		}

		if ([] === $this->map) {
			return $html;
		}

		// First pass, over the provider's answer: replace self-closing and paired
		// placeholder variants. The paired form is not just a provider quirk — the
		// sanitiser's DOM round trip rewrites every `<vtrans-ph id="0"/>` into
		// `<vtrans-ph id="0"></vtrans-ph>` — so this pattern has to consume it, and
		// the `s` (DOTALL) flag makes that work across lines.
		//
		// The inner `(?:(?!<vtrans-ph\b).)*?` is a tempered dot: a plain `.*?` would
		// happily run past further opening placeholders to reach some distant
		// `</vtrans-ph>` and swallow everything in between. It takes an answer that
		// pairs the placeholders itself and then drops one closing tag — something an
		// LLM provider normalising the markup can produce. The sanitiser's parser
		// balances such an answer before it gets here, so with `sanitize_html = 1`
		// the guard changes nothing; with `sanitize_html = 0` it is what keeps that
		// one missing tag from truncating the rest of the document.
		$html = preg_replace_callback(
			'/<' . preg_quote(self::PH_TAG, '/') . '\s+id=["\']?(\d+)["\']?\s*\/?>'
			. '(?:(?:(?!<' . preg_quote(self::PH_TAG, '/') . '\b).)*?<\/' . preg_quote(self::PH_TAG, '/') . '>)?/is',
			$this->resolvePlaceholder(...),
			$html
		) ?? $html;

		// Further passes, over content this method itself re-inserted: those fragments
		// only ever carry the self-closing form written by placeholder(), so the pattern
		// must NOT consume up to a closing tag. Doing so would let a nested placeholder
		// swallow everything up to any stray `</vtrans-ph>` left elsewhere in the document.
		$nestedPattern = '/<' . preg_quote(self::PH_TAG, '/') . '\s+id=["\']?(\d+)["\']?\s*\/?>/i';

		$previous = null;
		$maxIterations = count($this->map);

		for ($i = 0; $i < $maxIterations && $html !== $previous; $i++) {
			$previous = $html;
			$html = preg_replace_callback($nestedPattern, $this->resolvePlaceholder(...), $html) ?? $html;
		}

		return $html;
	}

	/**
	 * Resolve a single placeholder match to its stored original content.
	 * An unknown id is left untouched so nothing is silently dropped.
	 *
	 * @param array<int|string, string> $m
	 */
	private function resolvePlaceholder(array $m): string
	{
		$id = (int) $m[1];

		return $this->map[$id] ?? $m[0];
	}

	/**
	 * Return the number of placeholders that were created during {@see prepare()}.
	 */
	public function getPlaceholderCount(): int
	{
		return count($this->map);
	}

	/**
	 * Return the number of attribute values {@see prepare()} moved into the carrier.
	 */
	public function getAttributeCount(): int
	{
		return count($this->attrValues);
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * Replace <script>…</script>, <style>…</style>, etc. with placeholders.
	 */
	private function replaceAutoExcludeTags(string $html): string
	{
		foreach (self::AUTO_EXCLUDE_TAGS as $tag) {
			$html = preg_replace_callback(
				'/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/si',
				fn(array $m) => $this->placeholder($m[0]),
				$html
			) ?? $html;
		}

		return $html;
	}

	/**
	 * Replace entire elements carrying a specific boolean attribute with placeholders.
	 * Uses nesting-aware matching to correctly handle nested tags of the same type.
	 */
	private function replaceMarkedElements(string $html, string $attribute): string
	{
		$result = '';
		$pos = 0;
		$len = strlen($html);
		$quotedAttr = preg_quote($attribute, '/');

		while ($pos < $len) {
			if (!preg_match(
				'/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*(?<![\w-])' . $quotedAttr . '(?![\w-])[^>]*>/si',
				$html, $m, PREG_OFFSET_CAPTURE, $pos
			)) {
				$result .= substr($html, $pos);
				return $result;
			}

			$tagStart   = (int) $m[0][1];
			$openTag    = $m[0][0];
			$tagName    = strtolower($m[1][0]);
			$innerStart = $tagStart + strlen($openTag);

			$result .= substr($html, $pos, $tagStart - $pos);

			// Self-closing tag — store whole tag as placeholder.
			if (str_ends_with(rtrim($openTag), '/>')) {
				$result .= $this->placeholder($openTag);
				$pos = $innerStart;
				continue;
			}

			$closeInfo = $this->scanForMatchingClose($html, $innerStart, $tagName);
			if ($closeInfo === null) {
				// No matching closing tag — output open tag as-is and advance.
				$result .= $openTag;
				$pos = $innerStart;
				continue;
			}

			$result .= $this->placeholder(substr($html, $tagStart, $closeInfo[1] - $tagStart));
			$pos = $closeInfo[1];
		}

		return $result;
	}

	/**
	 * Protect elements with `translate="no"` or `class="…notranslate…"`.
	 * Uses nesting-aware matching to correctly handle nested tags of the same type.
	 *
	 * The *whole* element is replaced — opening tag included, exactly like
	 * `data-vtrans-exclude`. Masking only the inner content left the opening tag in
	 * the stream, where the sanitiser stripped its `on*` handlers: a marked
	 * `<button class="notranslate" onclick="…">` came back without its handler.
	 */
	private function replaceNoTranslateElements(string $html): string
	{
		$result = '';
		$pos = 0;
		$len = strlen($html);

		while ($pos < $len) {
			if (!preg_match(
				'/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*(?:translate\s*=\s*["\']no["\']|class\s*=\s*["\'][^"\']*(?<![\w-])notranslate(?![\w-])[^"\']*["\'])[^>]*(?<!\/)>/si',
				$html, $m, PREG_OFFSET_CAPTURE, $pos
			)) {
				$result .= substr($html, $pos);
				return $result;
			}

			$tagStart   = (int) $m[0][1];
			$openTag    = $m[0][0];
			$tagName    = strtolower($m[1][0]);
			$innerStart = $tagStart + strlen($openTag);

			$result .= substr($html, $pos, $tagStart - $pos);

			$closeInfo = $this->scanForMatchingClose($html, $innerStart, $tagName);
			if ($closeInfo === null) {
				// No matching closing tag — output open tag as-is and advance.
				$result .= $openTag;
				$pos = $innerStart;
				continue;
			}

			$result .= $this->placeholder(substr($html, $tagStart, $closeInfo[1] - $tagStart));
			$pos = $closeInfo[1];
		}

		return $result;
	}

	/**
	 * Scan from $pos to find the matching closing tag for $tagName,
	 * properly accounting for nesting depth.
	 *
	 * @return array{int, int}|null [closeTagStart, closeTagEnd]
	 */
	private function scanForMatchingClose(string $html, int $pos, string $tagName): ?array
	{
		$depth = 1;
		$len   = strlen($html);
		$qtag  = preg_quote($tagName, '/');

		while ($pos < $len) {
			// Next non-self-closing opening tag of the same name.
			preg_match('/<' . $qtag . '\b[^>]*(?<!\/)>/si', $html, $openM, PREG_OFFSET_CAPTURE, $pos);
			// Next closing tag of the same name.
			preg_match('/<\/' . $qtag . '\s*>/si', $html, $closeM, PREG_OFFSET_CAPTURE, $pos);

			$openPos  = isset($openM[0]) ? (int) $openM[0][1] : PHP_INT_MAX;
			$closePos = isset($closeM[0]) ? (int) $closeM[0][1] : PHP_INT_MAX;

			if ($closePos === PHP_INT_MAX) {
				return null; // Malformed HTML — no closing tag found.
			}

			if ($openPos < $closePos) {
				$depth++;
				$pos = $openPos + strlen($openM[0][0]);
			} else {
				$depth--;
				if ($depth === 0) {
					return [$closePos, $closePos + strlen($closeM[0][0])];
				}
				$pos = $closePos + strlen($closeM[0][0]);
			}
		}

		return null;
	}

	/**
	 * Replace translatable attribute values with tokens and append their text
	 * to the payload, one `<p><vtrans-attr id="N">…</vtrans-attr></p>` each.
	 *
	 * The carrier sits at the end rather than next to its element: inline, a
	 * provider would read it as part of the surrounding sentence and move words
	 * across it. Each value gets its own paragraph so HTML-aware engines treat
	 * it as a sentence of its own.
	 */
	private function extractAttributes(string $html): string
	{
		// Quote-aware: a `>` inside an attribute value does not end the tag.
		$html = preg_replace_callback(
			'/<([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/',
			$this->extractTagAttributes(...),
			$html
		) ?? $html;

		if ([] === $this->attrValues) {
			return $html;
		}

		$carrier = '';
		foreach ($this->attrValues as $id => $value) {
			$carrier .= '<p><' . self::ATTR_TAG . ' id="' . $id . '">'
				. htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')
				. '</' . self::ATTR_TAG . '></p>';
		}

		return $html . "\n" . $carrier;
	}

	/**
	 * @param array<int|string, string> $m [full tag, tag name, attribute string]
	 */
	private function extractTagAttributes(array $m): string
	{
		$tagName = strtolower($m[1]);
		$attributes = $m[2];

		// Our own placeholders, and void elements carrying an exclusion marker:
		// prepare() cannot mask those, as they have no closing tag to scan for.
		if (str_starts_with($tagName, 'vtrans-') || '' === trim($attributes) || self::isMarkedExcluded($attributes)) {
			return $m[0];
		}

		$inputType = null;
		if ('input' === $tagName && 1 === preg_match('/(?:^|\s)type\s*=\s*["\']?([a-z]+)/i', $attributes, $typeMatch)) {
			$inputType = strtolower($typeMatch[1]);
		}

		$attributes = preg_replace_callback(
			'/(\s)([^\s"\'>\/=]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/',
			function (array $a) use ($tagName, $inputType): string {
				$name = strtolower((string) $a[2]);
				if (!in_array($name, $this->translateAttributes, true)) {
					return (string) $a[0];
				}
				if ('value' === $name && ('input' !== $tagName || !in_array($inputType, ['button', 'submit', 'reset'], true))) {
					return (string) $a[0];
				}

				$value = html_entity_decode((string) ($a[3] ?? $a[4] ?? $a[5] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
				if (!self::isTranslatableValue($value)) {
					return (string) $a[0];
				}

				$id = count($this->attrValues);
				$this->attrValues[$id] = $value;

				return $a[1] . $a[2] . '="' . sprintf(self::ATTR_TOKEN, $id) . '"';
			},
			$attributes,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		) ?? $attributes;

		return '<' . $m[1] . $attributes . '>';
	}

	/**
	 * Pull the translated values out of the carrier block and write them back
	 * into their tags. A value the provider dropped falls back to the original.
	 */
	private function restoreAttributes(string $html): string
	{
		$tag = preg_quote(self::ATTR_TAG, '/');
		$translated = [];

		$html = preg_replace_callback(
			'/(?:<p\b[^>]*>\s*)?<' . $tag . '\s+id=["\']?(\d+)["\']?\s*>(.*?)<\/' . $tag . '>(?:\s*<\/p>)?/is',
			static function (array $m) use (&$translated): string {
				$translated[(int) $m[1]] = $m[2];
				return '';
			},
			$html
		) ?? $html;
		$html = rtrim($html);

		return preg_replace_callback(
			'/=\s*(["\']?)__vtrans_attr_(\d+)__\1/',
			function (array $m) use ($translated): string {
				$id = (int) $m[2];
				if (!isset($this->attrValues[$id])) {
					return $m[0];
				}

				// The carrier content is provider output: take its text only. Escaping
				// it again is what keeps a translated value inside its attribute.
				$value = isset($translated[$id])
					? trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($translated[$id]), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
					: '';
				if ('' === $value) {
					$value = $this->attrValues[$id];
				}

				return '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
			},
			$html
		) ?? $html;
	}

	/**
	 * True for values worth translating: some letters, and not a URL, path,
	 * anchor, file name or markup.
	 */
	private static function isTranslatableValue(string $value): bool
	{
		$value = trim($value);

		return '' !== $value
			&& 1 === preg_match('/\p{L}/u', $value)
			&& !str_contains($value, '<')
			&& 1 !== preg_match('~^(?:[a-z][a-z0-9+.\-]*:|//|www\.|[./#])\S*$~i', $value)
			&& 1 !== preg_match('/^\S+\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?|pdf)$/i', $value);
	}

	/** True when a tag's attribute string carries one of the exclusion markers. */
	private static function isMarkedExcluded(string $attributes): bool
	{
		return 1 === preg_match(
			'/(?<![\w-])data-vtrans-exclude(?![\w-])|translate\s*=\s*["\']?no\b|class\s*=\s*["\'][^"\']*(?<![\w-])notranslate(?![\w-])/i',
			$attributes
		);
	}

	/**
	 * Store original content and return a compact placeholder element.
	 */
	private function placeholder(string $original): string
	{
		$id = $this->nextId++;
		$this->map[$id] = $original;

		return '<' . self::PH_TAG . ' id="' . $id . '"/>';
	}
}
