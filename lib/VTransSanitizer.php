<?php

namespace FriendsOfRedaxo\VTrans;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * HTML sanitisation for content that is not admin-trusted.
 *
 * Two sources feed the `translation` column and neither is trustworthy:
 * the provider's response, and the edit form on the data page — which is
 * open to every user holding `vtrans[]`, a permission that does not imply
 * the right to put HTML on a page. Everything stored is rendered as HTML
 * in the frontend on every cache hit.
 *
 * Sanitisation therefore happens on the way *in*, never on the way out:
 * a cached row is returned verbatim, so the author's own `<script>` and
 * `<style>` blocks — which {@see VTransHtmlFilter} removes before the API
 * call and restores afterwards — survive untouched. They are re-inserted
 * after this class has run and never pass through it.
 */
final class VTransSanitizer
{
	private static ?HtmlSanitizer $sanitizer = null;

	/**
	 * Remove scripting from untrusted HTML while keeping ordinary article
	 * markup intact — links, images, classes, inline styles, tables.
	 */
	public static function sanitize(string $html): string
	{
		if ('' === trim($html)) {
			return $html;
		}

		return self::sanitizer()->sanitize($html);
	}

	/** True when $html contains something the sanitiser would remove. */
	public static function wouldChange(string $html): bool
	{
		return self::sanitize($html) !== $html;
	}

	private static function sanitizer(): HtmlSanitizer
	{
		if (null !== self::$sanitizer) {
			return self::$sanitizer;
		}

		$config = (new HtmlSanitizerConfig())
			->allowSafeElements()
			// The placeholder elements of VTransHtmlFilter and VTransHtmlChunker
			// must survive — the translated text still carries them at this point.
			// The sanitiser rewrites them to their paired form
			// (`<vtrans-ph id="0"></vtrans-ph>`), which both restore() regexes match.
			->allowElement('vtrans-ph', ['id'])
			->allowElement('vtrans-chunk', ['id'])
			// allowSafeElements() drops these, which would strip every class and
			// therefore every bit of styling out of a translated article.
			->allowAttribute('class', '*')
			->allowAttribute('id', '*')
			->allowAttribute('style', '*')
			->allowAttribute('title', '*')
			->allowAttribute('lang', '*')
			->allowAttribute('dir', '*')
			// Keep the markers VTransHtmlFilter looks for, so a sanitised round
			// trip does not silently disable the exclusion rules.
			->allowAttribute('translate', '*')
			// Without these, href and src are removed from every link and image.
			->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
			->allowMediaSchemes(['http', 'https'])
			->allowRelativeLinks()
			->allowRelativeMedias();

		return self::$sanitizer = new HtmlSanitizer($config);
	}
}
