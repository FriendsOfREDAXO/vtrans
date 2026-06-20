<?php

namespace FriendsOfRedaxo\VTrans\Provider;

use FriendsOfRedaxo\VTrans\VTransProviderInterface;
use FriendsOfRedaxo\VTrans\VTransProviderResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use rex_exception;

/**
 * DeepL provider using the REST API v2 via Guzzle (no SDK).
 */
class VTransDeepLProvider implements VTransProviderInterface
{
	private const DEFAULT_API_URL = 'https://api-free.deepl.com';
	private array $lastDebugData = [];
	private const CUSTOM_INSTRUCTIONS_TARGET_LANGS = ['DE', 'EN', 'ES', 'FR', 'IT', 'JA', 'KO', 'ZH'];

	public function supports(string $api): bool
	{
		return in_array($api, ['deepl-v2', 'deepl-api-free-v2', 'deepl-api-pro-v2'], true);
	}

	public function translate(string $text, ?string $srcLang, string $targetLang, string $format, array $modelData, array $requestOptions = []): VTransProviderResult
	{
		$promptContext = (string) ($requestOptions['promptContext'] ?? '');
		$debug = !empty($requestOptions['debug']);
		$config = $this->normalizeConfig($modelData['config']);
		$history = [];
		$stack = HandlerStack::create();
		$stack->push(Middleware::history($history));
		$client = new Client([
			'base_uri' => rtrim($config['apiUrl'], '/') . '/',
			'timeout' => $config['timeout'],
			'handler' => $stack,
		]);

		$params = [
			'text' => [(string) $text],
			'target_lang' => strtoupper($targetLang),
		];

		if (null !== $srcLang && '' !== trim($srcLang)) {
			$params['source_lang'] = strtoupper($srcLang);
		}

		if ('html' === $format) {
			$params['tag_handling'] = 'html';
		}

		if ('' !== $promptContext) {
			$params['context'] = $promptContext;
		}

		$customInstructions = $this->buildCustomInstructions($config, $requestOptions);
		if ([] !== $customInstructions && $this->supportsCustomInstructions($targetLang)) {
			$params['custom_instructions'] = $customInstructions;
		}

		try {
			$data = $this->sendRequest($client, $config['apiKey'], $params);
		} finally {
			if (!empty($history)) {
				$this->lastDebugData = $this->buildDebugData($history[0]);
			}
		}

		$translationEntry = $data['translations'][0] ?? [];
		$translation = (string) ($translationEntry['text'] ?? '');

		if ('' === $translation) {
			throw new rex_exception('DeepL returned an empty translation response.');
		}

		$resultData = [
			'model' => $modelData['key'],
			'api' => $config['api'],
			'apiUrl' => $config['apiUrl'],
			'detected_source_language' => $translationEntry['detected_source_language'] ?? null,
			'billed_characters' => $translationEntry['billed_characters'] ?? null,
			'model_type_used' => $translationEntry['model_type_used'] ?? null,
			'format' => $format,
			'context' => '' !== $promptContext ? $promptContext : null,
			'custom_instructions' => [] !== $customInstructions ? $customInstructions : null,
		];

		if ($debug && [] !== $this->lastDebugData) {
			$resultData['_debug'] = $this->lastDebugData;
		}

		return new VTransProviderResult($translation, $resultData);
	}

	public function getUsage(array $modelData): array
	{
		$config = $this->normalizeConfig($modelData['config']);
		$client = new Client([
			'base_uri' => rtrim($config['apiUrl'], '/') . '/',
			'timeout' => $config['timeout'],
		]);

		try {
			$response = $client->request('GET', 'v2/usage', [
				'headers' => [
					'Authorization' => 'DeepL-Auth-Key ' . $config['apiKey'],
				],
			]);

			$data = json_decode((string) $response->getBody(), true) ?? [];
		} catch (GuzzleException $e) {
			throw new rex_exception('DeepL usage request failed: ' . $e->getMessage(), $e);
		}

		$character = null;
		if (isset($data['character_count'], $data['character_limit'])) {
			$count = (int) $data['character_count'];
			$limit = (int) $data['character_limit'];
			$character = [
				'count' => $count,
				'limit' => $limit,
				'remaining' => max($limit - $count, 0),
				'limit_reached' => $count >= $limit,
			];
		}

		return [
			'provider' => 'deepl',
			'model' => (string) ($modelData['key'] ?? ''),
			'api' => $config['api'],
			'apiUrl' => $config['apiUrl'],
			'any_limit_reached' => null !== $character && $character['limit_reached'],
			'character' => $character,
			'document' => null,
			'teamDocument' => null,
		];
	}

	public function getAvailableSourceLanguages(): array
	{
		return [
			'auto'  => 'Automatic',
			'ar'    => 'Arabic (AR)',
			'bg'    => 'Bulgarian (BG)',
			'cs'    => 'Czech (CS)',
			'da'    => 'Danish (DA)',
			'de'    => 'German (DE)',
			'el'    => 'Greek (EL)',
			'en'    => 'English (EN)',
			'es'    => 'Spanish (ES)',
			'et'    => 'Estonian (ET)',
			'fi'    => 'Finnish (FI)',
			'fr'    => 'French (FR)',
			'hu'    => 'Hungarian (HU)',
			'id'    => 'Indonesian (ID)',
			'it'    => 'Italian (IT)',
			'ja'    => 'Japanese (JA)',
			'ko'    => 'Korean (KO)',
			'lt'    => 'Lithuanian (LT)',
			'lv'    => 'Latvian (LV)',
			'nb'    => 'Norwegian – Bokmål (NB)',
			'nl'    => 'Dutch (NL)',
			'pl'    => 'Polish (PL)',
			'pt'    => 'Portuguese (PT)',
			'ro'    => 'Romanian (RO)',
			'ru'    => 'Russian (RU)',
			'sk'    => 'Slovak (SK)',
			'sl'    => 'Slovenian (SL)',
			'sv'    => 'Swedish (SV)',
			'tr'    => 'Turkish (TR)',
			'uk'    => 'Ukrainian (UK)',
			'zh'    => 'Chinese (ZH)',
		];
	}

	public function getAvailableTargetLanguages(): array
	{
		return [
			'ar'      => 'Arabic (AR)',
			'bg'      => 'Bulgarian (BG)',
			'cs'      => 'Czech (CS)',
			'da'      => 'Danish (DA)',
			'de'      => 'German (DE)',
			'el'      => 'Greek (EL)',
			'en-gb'   => 'English – British (EN-GB)',
			'en-us'   => 'English – American (EN-US)',
			'es'      => 'Spanish (ES)',
			'et'      => 'Estonian (ET)',
			'fi'      => 'Finnish (FI)',
			'fr'      => 'French (FR)',
			'hu'      => 'Hungarian (HU)',
			'id'      => 'Indonesian (ID)',
			'it'      => 'Italian (IT)',
			'ja'      => 'Japanese (JA)',
			'ko'      => 'Korean (KO)',
			'lt'      => 'Lithuanian (LT)',
			'lv'      => 'Latvian (LV)',
			'nb'      => 'Norwegian – Bokmål (NB)',
			'nl'      => 'Dutch (NL)',
			'pl'      => 'Polish (PL)',
			'pt-br'   => 'Portuguese – Brazilian (PT-BR)',
			'pt-pt'   => 'Portuguese – European (PT-PT)',
			'ro'      => 'Romanian (RO)',
			'ru'      => 'Russian (RU)',
			'sk'      => 'Slovak (SK)',
			'sl'      => 'Slovenian (SL)',
			'sv'      => 'Swedish (SV)',
			'tr'      => 'Turkish (TR)',
			'uk'      => 'Ukrainian (UK)',
			'zh'      => 'Chinese – Simplified (ZH)',
			'zh-hans' => 'Chinese – Simplified (ZH-HANS)',
			'zh-hant' => 'Chinese – Traditional (ZH-HANT)',
		];
	}

	public function getDefaultTargetLanguage(): string
	{
		return 'en-gb';
	}

	public function getProviderLabel(): string
	{
		return 'DeepL';
	}

	public function getApiIdentifiers(): array
	{
		return ['deepl-v2'];
	}

	public function getConfigFields(): array
	{
		return [
			'api_key' => ['type' => 'text', 'label' => 'API Key', 'required' => true, 'column' => true],
			'api_url' => ['type' => 'text', 'label' => 'API URL', 'required' => false, 'column' => true, 'note' => 'Free-API: https://api-free.deepl.com — Pro-API: https://api.deepl.com'],
			'system_prompt' => ['type' => 'textarea', 'label' => 'Context', 'required' => false, 'column' => true],
			'customInstructions' => ['type' => 'textarea', 'label' => 'Custom Instructions', 'required' => false, 'note' => 'One instruction per line (max 10).'],
		];
	}

	public function validateConfig(array $values): array
	{
		$errors = [];
		if (empty(trim((string) ($values['api_key'] ?? '')))) {
			$errors['api_key'] = 'API Key is required.';
		}
		return $errors;
	}

	private function normalizeConfig(array $modelConfig): array
	{
		$api = trim((string) ($modelConfig['api'] ?? ''));
		$apiKey = trim((string) ($modelConfig['apiKey'] ?? ''));
		$apiUrl = trim((string) ($modelConfig['apiUrl'] ?? ''));
		$timeout = (int) ($modelConfig['timeout'] ?? 30);
		$timeout = max(1, min($timeout, 300));

		if ('' === $apiKey) {
			throw new rex_exception('DeepL requires an apiKey in the model configuration.');
		}

		// Resolve base URL: strip /v2/translate suffix if present, apply default.
		if ('' !== $apiUrl) {
			$apiUrl = (string) preg_replace('#/v2(/translate)?/?$#', '', $apiUrl);
		}
		if ('' === $apiUrl) {
			$apiUrl = self::DEFAULT_API_URL;
		}

		return [
			'api' => $api,
			'apiKey' => $apiKey,
			'apiUrl' => $apiUrl,
			'timeout' => $timeout,
			'systemPrompt' => trim((string) ($modelConfig['systemPrompt'] ?? '')),
			'customInstructions' => $modelConfig['customInstructions'] ?? [],
		];
	}

	private function sendRequest(Client $client, string $apiKey, array $params): array
	{
		try {
			$response = $client->request('POST', 'v2/translate', [
				'headers' => [
					'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
					'Content-Type' => 'application/json',
				],
				'json' => $params,
			]);

			$body = json_decode((string) $response->getBody(), true);
			if (!is_array($body)) {
				throw new rex_exception('DeepL returned invalid JSON.');
			}

			return $body;
		} catch (GuzzleException $e) {
			throw new rex_exception('DeepL API request failed: ' . $e->getMessage(), $e);
		}
	}

	private function buildCustomInstructions(array $config, array $requestOptions): array
	{
		$instructions = [];

		$configInstructions = $config['customInstructions'] ?? [];
		if (is_string($configInstructions) && '' !== trim($configInstructions)) {
			$configInstructions = [trim($configInstructions)];
		}

		if (is_array($configInstructions)) {
			foreach ($configInstructions as $instruction) {
				$instruction = trim((string) $instruction);
				if ('' !== $instruction) {
					$instructions[] = $instruction;
				}
			}
		}

		$requestInstructions = $requestOptions['customInstructions'] ?? [];
		if (is_string($requestInstructions)) {
			$requestInstructions = preg_split('/\r\n|\r|\n/', $requestInstructions) ?: [];
		}

		if (is_array($requestInstructions)) {
			foreach ($requestInstructions as $instruction) {
				$instruction = trim((string) $instruction);
				if ('' !== $instruction) {
					$instructions[] = $instruction;
				}
			}
		}

		$instructions = array_slice(array_values(array_unique($instructions)), 0, 10);

		return array_map(static fn(string $i): string => mb_strlen($i) <= 300 ? $i : mb_substr($i, 0, 300), $instructions);
	}

	private function supportsCustomInstructions(string $targetLang): bool
	{
		$baseLang = strtoupper((string) explode('-', $targetLang, 2)[0]);
		return in_array($baseLang, self::CUSTOM_INSTRUCTIONS_TARGET_LANGS, true);
	}

	public function getLastDebugData(): array
	{
		return $this->lastDebugData;
	}

	private function buildDebugData(array $transaction): array
	{
		$req = $transaction['request'] ?? null;
		$res = $transaction['response'] ?? null;

		$requestBody = null;
		if (null !== $req) {
			$bodyStr = (string) $req->getBody();
			$decoded = json_decode($bodyStr, true);
			$requestBody = is_array($decoded) ? $decoded : $bodyStr;
			// Mask the Authorization header.
			$headers = $req->getHeaders();
			if (isset($headers['Authorization'])) {
				$headers['Authorization'] = ['***'];
			}
		}

		return [
			'request' => null !== $req ? [
				'method' => $req->getMethod(),
				'uri' => (string) $req->getUri(),
				'headers' => $headers ?? [],
				'body' => $requestBody,
			] : null,
			'response' => null !== $res ? [
				'status' => $res->getStatusCode(),
				'headers' => $res->getHeaders(),
				'body' => json_decode((string) $res->getBody(), true),
			] : null,
		];
	}
}
