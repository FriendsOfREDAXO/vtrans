<?php

namespace FriendsOfRedaxo\VTrans;

/**
 * Provider-agnostic HTML pre-/post-processor.
 *
 * Before a translation request is sent to any provider this filter:
 * 1. Removes entire blocks that should never be sent (script, style, code,
 *    svg and elements with `data-vtrans-exclude`).
 * 2. Replaces content inside elements marked with `translate="no"` or the
 *    CSS class `notranslate` with compact placeholders.
 *
 * After the translated text comes back, all placeholders are resolved back
 * to their original content.
 */
class VTransHtmlFilter
{
	/** Tags whose content is never useful for translation. */
	private const AUTO_EXCLUDE_TAGS = ['script', 'style', 'code', 'svg'];

	/** Placeholder tag name — intentionally an unknown HTML element so APIs pass it through. */
	private const PH_TAG = 'vtrans-ph';

	/** @var array<int, string> id => original content */
	private array $map = [];

	/** Running placeholder counter. */
	private int $nextId = 0;

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

		// 3. Protect content of translate="no" and class="notranslate" elements.
		$html = $this->replaceNoTranslateElements($html);

		return $html;
	}

	/**
	 * Restore all placeholders in the translated text with the original content.
	 */
	public function restore(string $html): string
	{
		if ([] === $this->map) {
			return $html;
		}

		// Replace self-closing and paired placeholder variants that APIs may produce.
		// The `s` (DOTALL) flag ensures multi-line content between paired tags is consumed.
		return preg_replace_callback(
			'/<' . preg_quote(self::PH_TAG, '/') . '\s+id=["\']?(\d+)["\']?\s*\/?>(?:.*?<\/' . preg_quote(self::PH_TAG, '/') . '>)?/is',
			function (array $m): string {
				$id = (int) $m[1];
				return $this->map[$id] ?? $m[0];
			},
			$html
		) ?? $html;
	}

	/**
	 * Return the number of placeholders that were created during {@see prepare()}.
	 */
	public function getPlaceholderCount(): int
	{
		return count($this->map);
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
				'/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\b' . $quotedAttr . '\b[^>]*>/si',
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
	 * Protect inner content of elements with `translate="no"` or `class="…notranslate…"`.
	 * Uses nesting-aware matching to correctly handle nested tags of the same type.
	 * The outer element is kept; only its inner content is replaced with a placeholder.
	 */
	private function replaceNoTranslateElements(string $html): string
	{
		$result = '';
		$pos = 0;
		$len = strlen($html);

		while ($pos < $len) {
			if (!preg_match(
				'/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*(?:translate\s*=\s*["\']no["\']|class\s*=\s*["\'][^"\']*\bnotranslate\b[^"\']*["\'])[^>]*(?<!\/)>/si',
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

			[$closeStart, $closeEnd] = $closeInfo;
			$inner    = substr($html, $innerStart, $closeStart - $innerStart);
			$closeTag = substr($html, $closeStart, $closeEnd - $closeStart);

			$result .= $openTag . $this->placeholder($inner) . $closeTag;
			$pos = $closeEnd;
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

		while ($pos < $len && $depth > 0) {
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
	 * Store original content and return a compact placeholder element.
	 */
	private function placeholder(string $original): string
	{
		$id = $this->nextId++;
		$this->map[$id] = $original;

		return '<' . self::PH_TAG . ' id="' . $id . '"/>';
	}
}
