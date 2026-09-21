<?php

namespace FriendsOfRedaxo\VTrans\Provider;

use FriendsOfRedaxo\VTrans\VTransProviderInterface;
use FriendsOfRedaxo\VTrans\VTransProviderResult;
use rex_addon;
use rex_exception;
use rex_i18n;

/**
 * Translation provider that delegates to the ai_platform addon.
 *
 * Instead of talking to an LLM endpoint directly, this provider hands the
 * translation prompt to a configured ai_platform text profile. Provider, model,
 * API key and invoke options all live in that profile — this class only supplies
 * the profile id and the translation instructions.
 */
class VTransAiPlatformProvider implements VTransProviderInterface
{
	/** @var array<string, mixed> */
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'ai_platform' === $api;
	}

	/**
	 * @param array<string, mixed> $modelData
	 * @param array<string, mixed> $requestOptions
	 */
	public function translate(string $text, ?string $srcLang, string $targetLang, string $format, array $modelData, array $requestOptions = []): VTransProviderResult
	{
		$debug = (bool) ($requestOptions['debug'] ?? false);
		$config = $this->normalizeConfig($this->normalizeModelConfig($modelData['config'] ?? []));

		if (!$this->isAiPlatformAvailable()) {
			throw new rex_exception('The ai_platform addon is not installed or not available.');
		}

		$service = \FriendsOfRedaxo\AiPlatform\Service::getInstance();

		$profile = $service->getProfile($config['profileId']);
		if (null === $profile) {
			throw new rex_exception('ai_platform text profile not found or inactive: ' . $config['profileId']);
		}
		if ('text' !== ($profile['type'] ?? '')) {
			throw new rex_exception('ai_platform profile ' . $config['profileId'] . ' is not a text profile.');
		}

		// The translation directive (source→target, format) is fixed and deterministic.
		// Any custom instructions come from the vTrans connection's system-prompt field,
		// passed here as promptContext — vTrans folds that into its cache hash, so a prompt
		// change invalidates cached translations. The ai_platform profile's own system
		// prompt is intentionally NOT mixed in: vTrans cannot see it and would keep serving
		// stale cached translations when it changes.
		$systemPrompt = $this->buildSystemPrompt(
			$srcLang,
			$targetLang,
			$format,
			$this->normalizeString($requestOptions['promptContext'] ?? null)
		);

		$this->lastDebugData = [
			'request' => [
				'provider' => 'ai_platform',
				'profile_id' => $config['profileId'],
				'profile_name' => $this->normalizeString($profile['name'] ?? ''),
				'ai_provider' => $this->normalizeString($profile['provider'] ?? ''),
				'model' => $this->normalizeString($profile['model'] ?? ''),
				'format' => $format,
				'system_prompt' => $systemPrompt,
			],
		];

		try {
			$translation = trim($service->generateText($this->normalizeString($text), $systemPrompt, $config['profileId']));
		} catch (\Throwable $e) {
			throw new rex_exception('ai_platform translation failed: ' . $e->getMessage(), $e instanceof \Exception ? $e : null);
		}

		if ('' === $translation) {
			throw new rex_exception('ai_platform returned an empty translation response.');
		}

		$this->lastDebugData['response'] = [
			'text' => $translation,
		];

		$resultData = [
			'model' => $modelData['key'] ?? '',
			'api' => 'ai_platform',
			'provider_model' => $this->normalizeString($profile['model'] ?? ''),
			'ai_provider' => $this->normalizeString($profile['provider'] ?? ''),
			'profile_id' => $config['profileId'],
			'format' => $format,
			'usage' => null,
		];

		if ($debug) {
			$resultData['_debug'] = $this->lastDebugData;
		}

		return new VTransProviderResult($translation, $resultData);
	}

	/**
	 * @param array<string, mixed> $modelData
	 * @return array<string, mixed>
	 */
	public function getUsage(array $modelData): array
	{
		$config = $this->normalizeModelConfig($modelData['config'] ?? []);

		return [
			'provider' => 'ai_platform',
			'model' => $this->normalizeString($modelData['key'] ?? null),
			'api' => 'ai_platform',
			'profile_id' => $this->normalizeInt($config['profile_id'] ?? null, 0),
			'usage_supported' => false,
			'character' => null,
		];
	}

	public function getAvailableSourceLanguages(): array
	{
		return [
			'auto'  => 'Automatic',
			'af'    => 'Afrikaans (AF)',
			'ar'    => 'Arabic (AR)',
			'bg'    => 'Bulgarian (BG)',
			'bn'    => 'Bengali (BN)',
			'ca'    => 'Catalan (CA)',
			'cs'    => 'Czech (CS)',
			'cy'    => 'Welsh (CY)',
			'da'    => 'Danish (DA)',
			'de'    => 'German (DE)',
			'el'    => 'Greek (EL)',
			'en'    => 'English (EN)',
			'eo'    => 'Esperanto (EO)',
			'es'    => 'Spanish (ES)',
			'et'    => 'Estonian (ET)',
			'eu'    => 'Basque (EU)',
			'fa'    => 'Persian (FA)',
			'fi'    => 'Finnish (FI)',
			'fr'    => 'French (FR)',
			'ga'    => 'Irish (GA)',
			'gl'    => 'Galician (GL)',
			'gu'    => 'Gujarati (GU)',
			'he'    => 'Hebrew (HE)',
			'hi'    => 'Hindi (HI)',
			'hr'    => 'Croatian (HR)',
			'hu'    => 'Hungarian (HU)',
			'hy'    => 'Armenian (HY)',
			'id'    => 'Indonesian (ID)',
			'is'    => 'Icelandic (IS)',
			'it'    => 'Italian (IT)',
			'ja'    => 'Japanese (JA)',
			'ko'    => 'Korean (KO)',
			'lt'    => 'Lithuanian (LT)',
			'lv'    => 'Latvian (LV)',
			'mk'    => 'Macedonian (MK)',
			'ms'    => 'Malay (MS)',
			'mt'    => 'Maltese (MT)',
			'nb'    => 'Norwegian – Bokmål (NB)',
			'nl'    => 'Dutch (NL)',
			'pl'    => 'Polish (PL)',
			'pt'    => 'Portuguese (PT)',
			'ro'    => 'Romanian (RO)',
			'ru'    => 'Russian (RU)',
			'sk'    => 'Slovak (SK)',
			'sl'    => 'Slovenian (SL)',
			'sq'    => 'Albanian (SQ)',
			'sr'    => 'Serbian (SR)',
			'sv'    => 'Swedish (SV)',
			'sw'    => 'Swahili (SW)',
			'ta'    => 'Tamil (TA)',
			'te'    => 'Telugu (TE)',
			'th'    => 'Thai (TH)',
			'tl'    => 'Filipino (TL)',
			'tr'    => 'Turkish (TR)',
			'uk'    => 'Ukrainian (UK)',
			'ur'    => 'Urdu (UR)',
			'vi'    => 'Vietnamese (VI)',
			'zh'    => 'Chinese – Simplified (ZH)',
			'zh-tw' => 'Chinese – Traditional (ZH-TW)',
		];
	}

	public function getAvailableTargetLanguages(): array
	{
		$langs = $this->getAvailableSourceLanguages();
		unset($langs['auto']);
		return $langs;
	}

	public function getDefaultTargetLanguage(): string
	{
		return 'en';
	}

	public function getProviderLabel(): string
	{
		return rex_i18n::rawMsg('vtrans_provider_ai_platform');
	}

	/** @return list<string> */
	public function getApiIdentifiers(): array
	{
		return ['ai_platform'];
	}

	public function getConfigFields(): array
	{
		return [
			'profile_id' => ['type' => 'select', 'label' => rex_i18n::rawMsg('vtrans_connections_ai_profile'), 'required' => true, 'options' => $this->profileOptions(), 'option_data' => $this->profileOptionData(), 'note' => $this->profileHint()],
			'system_prompt' => ['type' => 'textarea', 'label' => rex_i18n::rawMsg('vtrans_connections_ai_systemprompt'), 'required' => false, 'column' => true, 'note' => rex_i18n::rawMsg('vtrans_connections_ai_systemprompt_note')],
		];
	}

	/** @param array<string, mixed> $values @return array<string, string> */
	public function validateConfig(array $values): array
	{
		$errors = [];
		$profileId = $this->normalizeInt($values['profile_id'] ?? null, 0);
		if ($profileId <= 0) {
			$errors['profile_id'] = rex_i18n::rawMsg('vtrans_connections_ai_profile_required');
		} elseif ($this->isAiPlatformAvailable()) {
			$profile = \FriendsOfRedaxo\AiPlatform\Service::getInstance()->getProfile($profileId);
			if (null === $profile) {
				$errors['profile_id'] = rex_i18n::rawMsg('vtrans_connections_ai_profile_invalid');
			} elseif ('text' !== ($profile['type'] ?? '')) {
				$errors['profile_id'] = rex_i18n::rawMsg('vtrans_connections_ai_profile_not_text');
			}
		}
		return $errors;
	}

	/** @return array<string, mixed> */
	public function getLastDebugData(): array
	{
		return $this->lastDebugData;
	}

	private function isAiPlatformAvailable(): bool
	{
		return rex_addon::get('ai_platform')->isAvailable()
			&& class_exists(\FriendsOfRedaxo\AiPlatform\Service::class);
	}

	/**
	 * @param array<string, mixed> $modelConfig
	 * @return array{profileId: int}
	 */
	private function normalizeConfig(array $modelConfig): array
	{
		$profileId = $this->normalizeInt($modelConfig['profile_id'] ?? null, 0);
		if ($profileId <= 0) {
			throw new rex_exception('ai_platform provider requires a valid profile_id in the connection configuration.');
		}

		return [
			'profileId' => $profileId,
		];
	}

	/** @return array<string, mixed> */
	private function normalizeModelConfig(mixed $config): array
	{
		if (!is_array($config)) {
			return [];
		}

		$normalized = [];
		foreach ($config as $key => $value) {
			$normalized[is_string($key) ? $key : (string) $key] = $value;
		}

		return $normalized;
	}

	/**
	 * Build the per-call system prompt: the fixed translation directive plus the vTrans
	 * connection's system prompt (passed as promptContext). The ai_platform profile's own
	 * system prompt is deliberately excluded — vTrans folds the connection prompt into its
	 * cache hash, so keeping the effective prompt on the vTrans side is what lets a prompt
	 * change invalidate cached translations.
	 */
	private function buildSystemPrompt(?string $srcLang, string $targetLang, string $format, string $promptContext): string
	{
		$source = null !== $srcLang && '' !== trim($srcLang) ? trim($srcLang) : 'auto-detect';
		$formatInstruction = match ($format) {
			'html' => 'Input is HTML. Preserve HTML tags, attributes and structure. Translate only user-visible text.',
			default => 'Input is plain text. Return plain text only.',
		};

		$systemParts = [
			'You are a professional translation engine.',
			'Translate from ' . $source . ' to ' . $targetLang . '.',
			$formatInstruction,
			'Return only the translated text, without explanations, quotes, markdown fences, or extra comments.',
		];

		$promptContext = trim($promptContext);
		if ('' !== $promptContext) {
			$systemParts[] = "Additional instructions:\n" . $promptContext;
		}

		return implode("\n\n", $systemParts);
	}

	/**
	 * Options for the profile select: text profiles keyed by id.
	 *
	 * @return array<int|string, string>
	 */
	private function profileOptions(): array
	{
		if (!$this->isAiPlatformAvailable()) {
			return [];
		}

		$options = [];
		foreach (\FriendsOfRedaxo\AiPlatform\Service::getInstance()->getProfiles('text') as $profile) {
			$id = $this->normalizeInt($profile['id'] ?? null, 0);
			if ($id <= 0) {
				continue;
			}
			$name = $this->normalizeString($profile['name'] ?? '');
			$providerModel = trim($this->normalizeString($profile['provider'] ?? '') . ' / ' . $this->normalizeString($profile['model'] ?? ''), ' /');
			$options[(string) $id] = $name . ('' !== $providerModel ? ' (' . $providerModel . ')' : '');
		}

		return $options;
	}

	/**
	 * Per-option data attributes for the profile select: the profile's max_tokens,
	 * used by the form to derive vTrans' "max characters" guideline.
	 *
	 * @return array<int|string, array<string, string>>
	 */
	private function profileOptionData(): array
	{
		if (!$this->isAiPlatformAvailable()) {
			return [];
		}

		$data = [];
		foreach (\FriendsOfRedaxo\AiPlatform\Service::getInstance()->getProfiles('text') as $profile) {
			$id = $this->normalizeInt($profile['id'] ?? null, 0);
			if ($id <= 0) {
				continue;
			}
			$maxTokens = $this->normalizeInt($profile['max_tokens'] ?? null, 0);
			if ($maxTokens > 0) {
				$data[(string) $id] = ['data-max-tokens' => (string) $maxTokens];
			}
		}

		return $data;
	}

	private function profileHint(): string
	{
		if (!$this->isAiPlatformAvailable()) {
			return rex_i18n::rawMsg('vtrans_connections_ai_profile_note_unavailable');
		}

		if ([] === $this->profileOptions()) {
			return rex_i18n::rawMsg('vtrans_connections_ai_profile_note_empty');
		}

		return rex_i18n::rawMsg('vtrans_connections_ai_profile_note');
	}

	private function normalizeString(mixed $value): string
	{
		return is_string($value) ? $value : '';
	}

	private function normalizeInt(mixed $value, int $default): int
	{
		if (is_int($value)) {
			return $value;
		}

		if (is_string($value) && is_numeric($value)) {
			return (int) $value;
		}

		if (is_float($value)) {
			return (int) $value;
		}

		return $default;
	}
}
