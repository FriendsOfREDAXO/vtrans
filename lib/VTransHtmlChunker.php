<?php

namespace FriendsOfRedaxo\VTrans;

/**
 * Extracts elements marked with `data-vtrans-key` from an HTML string,
 * replaces them with compact self-closing placeholders, and reassembles
 * the HTML after each block has been translated separately.
 *
 * Nesting is supported: inner `data-vtrans-key` elements are preserved inside
 * extracted blocks. Pass each block through a new VTransHtmlChunker instance
 * to process them recursively.
 *
 * The `data-vtrans-key` attribute is stripped from extracted elements before
 * storing them so that recursive processing does not re-extract the same root.
 */
class VTransHtmlChunker
{
	/** Placeholder element name — an unknown tag so providers pass it through unchanged. */
	private const PH_TAG = 'vtrans-chunk';

	/** @var array<int, array{key: string, html: string}> id => {vtrans-key, original HTML} */
	private array $chunks = [];

	private int $nextId = 0;

	/**
	 * Extract all top-level `data-vtrans-key` elements from $html.
	 * Each matched element is replaced by `<vtrans-chunk id="N"/>`.
	 * The `data-vtrans-key` attribute is stripped from the extracted element's opening tag.
	 *
	 * @return string Shell HTML with vtrans-chunk placeholders.
	 */
	public function extract(string $html): string
	{
		$this->chunks = [];
		$this->nextId = 0;

		$result = '';
		$pos    = 0;
		$len    = strlen($html);

		while ($pos < $len) {
			// Match opening tag of an element carrying data-vtrans-key (non-self-closing).
			if (!preg_match(
				'/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bdata-vtrans-key\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>\'"\\/]*))[^>]*(?<!\/)>/si',
				$html, $m, PREG_OFFSET_CAPTURE, $pos,
			)) {
				$result .= substr($html, $pos);
				return $result;
			}

			$tagStart   = (int) $m[0][1];
			$openTag    = $m[0][0];
			$tagName    = strtolower($m[1][0]);
			// Determine key from whichever quote style matched (offset -1 = group not matched).
			$key        = $m[2][1] !== -1 ? $m[2][0] : ($m[3][1] !== -1 ? $m[3][0] : $m[4][0]);
			$innerStart = $tagStart + strlen($openTag);

			// Append content between last position and this match unchanged.
			$result .= substr($html, $pos, $tagStart - $pos);

			// Strip data-vtrans-key attribute so recursive processing does not re-extract this root.
			$cleanOpenTag = preg_replace(
				'/\s+\bdata-vtrans-key\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>\'"\\/]*)/i',
				'',
				$openTag,
			);

			$closeInfo = $this->scanForMatchingClose($html, $innerStart, $tagName);
			if (null === $closeInfo) {
				// Malformed HTML — emit the opening tag as-is and advance past it.
				$result .= $openTag;
				$pos = $innerStart;
				continue;
			}

			[$closeStart, $closeEnd] = $closeInfo;
			$inner    = substr($html, $innerStart, $closeStart - $innerStart);
			$closeTag = substr($html, $closeStart, $closeEnd - $closeStart);

			$id = $this->nextId++;
			$this->chunks[$id] = [
				'key'  => $key,
				'html' => $cleanOpenTag . $inner . $closeTag,
			];

			$result .= '<' . self::PH_TAG . ' id="' . $id . '"/>';
			$pos = $closeEnd;
		}

		return $result;
	}

	/** Whether any data-vtrans-key blocks were found during the last {@see extract()} call. */
	public function hasChunks(): bool
	{
		return [] !== $this->chunks;
	}

	/**
	 * Return extracted chunks indexed by their internal placeholder id.
	 *
	 * @return array<int, array{key: string, html: string}>
	 */
	public function getChunks(): array
	{
		return $this->chunks;
	}

	/**
	 * Reinsert translated block HTML into the translated shell.
	 * Any id missing from $translations falls back to the original block HTML.
	 *
	 * @param array<int, string> $translations id => translated HTML
	 */
	public function restore(string $html, array $translations): string
	{
		return preg_replace_callback(
			'/<' . preg_quote(self::PH_TAG, '/') . '\s+id=["\']?(\d+)["\']?\s*\/?>(?:<\/' . preg_quote(self::PH_TAG, '/') . '>)?/i',
			function (array $m) use ($translations): string {
				$id = (int) $m[1];
				return $translations[$id] ?? ($this->chunks[$id]['html'] ?? $m[0]);
			},
			$html,
		) ?? $html;
	}

	/**
	 * Scan forward from $pos to find the matching closing `</$tagName>`,
	 * accounting for nested elements of the same tag name.
	 *
	 * @return array{int, int}|null [closeTagStart, closeTagEnd], or null if not found.
	 */
	private function scanForMatchingClose(string $html, int $pos, string $tagName): ?array
	{
		$depth = 1;
		$len   = strlen($html);
		$qtag  = preg_quote($tagName, '/');

		while ($pos < $len && $depth > 0) {
			preg_match('/<' . $qtag . '\b[^>]*(?<!\/)>/si', $html, $openM, PREG_OFFSET_CAPTURE, $pos);
			preg_match('/<\/' . $qtag . '\s*>/si', $html, $closeM, PREG_OFFSET_CAPTURE, $pos);

			$openPos  = isset($openM[0]) ? (int) $openM[0][1] : PHP_INT_MAX;
			$closePos = isset($closeM[0]) ? (int) $closeM[0][1] : PHP_INT_MAX;

			if (PHP_INT_MAX === $closePos) {
				return null; // Malformed HTML — no closing tag found.
			}

			if ($openPos < $closePos) {
				$depth++;
				$pos = $openPos + strlen($openM[0][0]);
			} else {
				$depth--;
				if (0 === $depth) {
					return [$closePos, $closePos + strlen($closeM[0][0])];
				}
				$pos = $closePos + strlen($closeM[0][0]);
			}
		}

		return null;
	}
}
