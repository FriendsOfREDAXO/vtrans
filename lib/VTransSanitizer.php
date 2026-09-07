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
 * a cached row is returned verbatim, so everything {@see VTransHtmlFilter}
 * masks before the API call — `<script>`, `<style>`, `data-vtrans-exclude`,
 * `translate="no"` and `.notranslate` elements — survives untouched. Those
 * fragments are re-inserted after this class has run and never pass through it.
 *
 * The behaviour is configurable per connection: `sanitize_html` switches it
 * off entirely, `sanitize_allow_extra` widens the allowlist. Both default to
 * the safe setting, so a connection that was never touched keeps sanitising.
 */
final class VTransSanitizer
{
	/** @var array<string, HtmlSanitizer> spec => sanitizer */
	private static array $sanitizers = [];

	/**
	 * Remove scripting from untrusted HTML while keeping ordinary article
	 * markup intact — links, images, classes, inline styles, tables.
	 *
	 * $connectionKey is the `connection` value of the row being written; it
	 * decides which configuration applies. An unknown or missing key falls
	 * back to the default configuration, never to "no sanitisation".
	 */
	public static function sanitize(string $html, ?string $connectionKey = null): string
	{
		$connection = self::resolveConnection($connectionKey);

		return self::sanitizeWith(
			$html,
			null === $connection || $connection->isSanitizeHtml(),
			$connection?->getSanitizeAllowExtra(),
		);
	}

	/**
	 * Sanitise with explicit settings instead of a connection lookup.
	 * Kept public so the behaviour can be exercised without a database.
	 */
	public static function sanitizeWith(string $html, bool $enabled, ?string $allowExtra = null): string
	{
		if (!$enabled || '' === trim($html)) {
			return $html;
		}

		return self::sanitizer((string) $allowExtra)->sanitize($html);
	}

	/** True when $html contains something the sanitiser would remove. */
	public static function wouldChange(string $html, ?string $connectionKey = null): bool
	{
		return self::sanitize($html, $connectionKey) !== $html;
	}

	/** True when the given connection sanitises what is written for it. */
	public static function isEnabledFor(?string $connectionKey): bool
	{
		$connection = self::resolveConnection($connectionKey);

		return null === $connection || $connection->isSanitizeHtml();
	}

	/**
	 * Split the free-text allowlist into elements and attributes.
	 *
	 * Entries are separated by whitespace, commas or semicolons. `<iframe>`
	 * allows an element (with the safe attributes of the W3C reference),
	 * a bare `onclick` allows an attribute on every allowed element.
	 * Anything that is not a valid HTML name is ignored.
	 *
	 * @return array{elements: list<string>, attributes: list<string>}
	 */
	public static function parseAllowExtra(?string $spec): array
	{
		$elements = [];
		$attributes = [];

		foreach (preg_split('/[\s,;]+/', strtolower(trim((string) $spec))) ?: [] as $token) {
			if ('' === $token) {
				continue;
			}

			if (str_starts_with($token, '<')) {
				$name = trim($token, '<>/');
				if (1 === preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
					$elements[$name] = true;
				}
				continue;
			}

			if (1 === preg_match('/^[a-z_:][a-z0-9_:.-]*$/', $token)) {
				$attributes[$token] = true;
			}
		}

		return [
			'elements' => array_keys($elements),
			'attributes' => array_keys($attributes),
		];
	}

	private static function resolveConnection(?string $connectionKey): ?VTransConnection
	{
		if (null === $connectionKey || '' === trim($connectionKey)) {
			return null;
		}

		return VTransConnection::getByKey(trim($connectionKey));
	}

	private static function sanitizer(string $allowExtra): HtmlSanitizer
	{
		$extra = self::parseAllowExtra($allowExtra);
		$cacheKey = implode('|', $extra['elements']) . '#' . implode('|', $extra['attributes']);

		if (isset(self::$sanitizers[$cacheKey])) {
			return self::$sanitizers[$cacheKey];
		}

		$config = (new HtmlSanitizerConfig())
			->allowSafeElements()
			// The placeholder elements of VTransHtmlFilter and VTransHtmlChunker
			// must survive — the translated text still carries them at this point.
			// The sanitiser rewrites them to their paired form
			// (`<vtrans-ph id="0"></vtrans-ph>`), which both restore() regexes match.
			->allowElement('vtrans-ph', ['id'])
			->allowElement('vtrans-chunk', ['id']);

		// Extra elements come first: allowAttribute('…', '*') below only reaches
		// elements that are already allowed at that point.
		foreach ($extra['elements'] as $element) {
			$config = $config->allowElement($element, '*');
		}

		$config = $config
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

		foreach ($extra['attributes'] as $attribute) {
			$config = $config->allowAttribute($attribute, '*');
		}

		return self::$sanitizers[$cacheKey] = new HtmlSanitizer($config);
	}
}
