<?php

namespace FriendsOfRedaxo\VTrans;

/**
 * Builds the system prompt for LLM-based providers (openai, ai_platform), so both
 * treat a connection's system prompt the same way.
 *
 * A configured system prompt replaces the default template entirely; both may use
 * the placeholders listed in PLACEHOLDERS. The format rule is always appended and
 * cannot be replaced: for HTML it tells the model to keep the markup and the
 * <vtrans-ph> placeholders that VTransHtmlFilter restores afterwards.
 *
 * Pure PHP without REDAXO dependencies, so tests/sanitizer.php can exercise it.
 */
final class VTransPrompt
{
	public const DEFAULT_TEMPLATE = "You are a professional translation engine.\n"
		. "Translate from {source_lang_name} to {target_lang_name}.\n"
		. 'Return only the translated text, without explanations, quotes, markdown fences, or extra comments.';

	/** @var list<string> */
	public const PLACEHOLDERS = ['{source_lang}', '{target_lang}', '{source_lang_name}', '{target_lang_name}'];

	private const FORMAT_RULE_HTML = 'Input is HTML. Preserve HTML tags, attributes and structure, and keep every <vtrans-ph> element exactly as it is. Translate the text inside <vtrans-attr> elements but keep the elements themselves, and leave tokens like __vtrans_attr_0__ and __vtrans_twig_0__ unchanged. Change only user-visible text.';

	private const FORMAT_RULE_TEXT = 'Input is plain text. Return plain text only.';

	/**
	 * @param string $systemPrompt configured prompt of the connection; empty = default template
	 * @param string $promptContext what VTrans hands over as promptContext: connection prompt + request context
	 * @param list<string> $customInstructions
	 * @param array<string, string> $languageLabels provider language map, code => label ("German (DE)")
	 */
	public static function build(string $systemPrompt, ?string $srcLang, string $targetLang, string $format, string $promptContext, array $customInstructions, array $languageLabels = []): string
	{
		$systemPrompt = trim($systemPrompt);
		$template = '' !== $systemPrompt ? $systemPrompt : self::DEFAULT_TEMPLATE;

		$parts = [
			self::resolvePlaceholders($template, $srcLang, $targetLang, $languageLabels),
			'html' === $format ? self::FORMAT_RULE_HTML : self::FORMAT_RULE_TEXT,
		];

		$context = self::requestContext($promptContext, $systemPrompt);
		if ('' !== $context) {
			$parts[] = "Context:\n" . $context;
		}

		if ([] !== $customInstructions) {
			$parts[] = "Additional instructions:\n- " . implode("\n- ", $customInstructions);
		}

		return implode("\n\n", $parts);
	}

	/**
	 * Whether a configured prompt names the target language. A replacing prompt
	 * without it leaves the model guessing which language to produce.
	 */
	public static function mentionsTargetLanguage(string $systemPrompt): bool
	{
		return str_contains($systemPrompt, '{target_lang}') || str_contains($systemPrompt, '{target_lang_name}');
	}

	/** @param array<string, string> $languageLabels */
	private static function resolvePlaceholders(string $template, ?string $srcLang, string $targetLang, array $languageLabels): string
	{
		$srcLang = null !== $srcLang ? trim($srcLang) : '';

		return strtr($template, [
			'{source_lang}' => '' !== $srcLang ? $srcLang : 'auto',
			'{target_lang}' => $targetLang,
			'{source_lang_name}' => '' !== $srcLang ? self::languageName($srcLang, $languageLabels) : 'the source language (detect it)',
			'{target_lang_name}' => self::languageName($targetLang, $languageLabels),
		]);
	}

	/**
	 * "German (DE)" → "German". Falls back to the code when the provider has no label.
	 *
	 * @param array<string, string> $languageLabels
	 */
	private static function languageName(string $code, array $languageLabels): string
	{
		$needle = strtolower(str_replace('_', '-', $code));
		foreach ($languageLabels as $key => $label) {
			if (strtolower(str_replace('_', '-', (string) $key)) === $needle) {
				$name = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $label));
				return '' !== $name ? $name : $code;
			}
		}

		return $code;
	}

	/**
	 * VTrans passes the connection prompt and the request context joined as one
	 * promptContext (that is what goes into the cache hash). The connection prompt is
	 * already the template, so only the request part is added as context.
	 */
	private static function requestContext(string $promptContext, string $systemPrompt): string
	{
		$promptContext = trim($promptContext);
		if ('' === $systemPrompt || '' === $promptContext) {
			return $promptContext;
		}
		if ($promptContext === $systemPrompt) {
			return '';
		}
		if (str_starts_with($promptContext, $systemPrompt . "\n\n")) {
			return trim(substr($promptContext, strlen($systemPrompt . "\n\n")));
		}

		return $promptContext;
	}
}
