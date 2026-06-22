<?php

namespace FriendsOfRedaxo\VTrans\Provider;

use FriendsOfRedaxo\VTrans\VTransProviderInterface;
use FriendsOfRedaxo\VTrans\VTransProviderResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use rex_exception;

/**
 * LibreTranslate provider (self-hosted or hosted API endpoint).
 */
class VTransLibreTranslateProvider implements VTransProviderInterface
{
	/** @var array<string, mixed> */
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'libretranslate-v1' === $api;
	}

	/**
	 * @param array<string, mixed> $modelData
	 * @param array<string, mixed> $requestOptions
	 */
	public function translate(string $text, ?string $srcLang, string $targetLang, string $format, array $modelData, array $requestOptions = []): VTransProviderResult
	{
		$debug = !empty($requestOptions['debug']);
		$config = $this->normalizeConfig($this->normalizeModelConfig($modelData['config']));
		$history = [];
		$stack = HandlerStack::create();
		$stack->push(Middleware::history($history));
		$client = new Client([
			'timeout' => $this->normalizeInt($config['timeout'] ?? null, 60),
			'handler' => $stack,
		]);

		$payload = [
			'q' => $this->normalizeString($text),
			'source' => null !== $srcLang && '' !== trim($srcLang) ? $this->normalizeLanguageCode($srcLang) : 'auto',
			'target' => $this->normalizeLanguageCode($targetLang),
			'format' => 'html' === $format ? 'html' : 'text',
		];

		if ('' !== $config['apiKey']) {
			$payload['api_key'] = $config['apiKey'];
		}

		$translateUrl = $this->normalizeString($config['translateUrl'] ?? null);
		try {
			$data = $this->sendTranslateRequest($client, $translateUrl, $payload);
		} finally {
			if (!empty($history)) {
				$debugTransaction = $history[0] ?? null;
				$debugData = [];
				if (is_array($debugTransaction)) {
					/** @var array<string, mixed> $debugData */
					$debugData = $debugTransaction;
				}
				$this->lastDebugData = $this->buildDebugData($debugData);
			}
		}
		$detectedLanguage = is_array($data['detectedLanguage'] ?? null) ? $data['detectedLanguage'] : [];
		$translation = $this->normalizeString($data['translatedText'] ?? null);

		if ('' === $translation) {
			throw new rex_exception('LibreTranslate returned an empty translation response.');
		}

		$resultData = [
			'model' => $this->normalizeString($modelData['key'] ?? null),
			'api' => $this->normalizeString($config['api'] ?? null),
			'apiUrl' => $this->normalizeString($config['apiUrl'] ?? null),
			'detected_source_language' => $detectedLanguage['language'] ?? null,
			'format' => $format,
		];

		if ($debug && [] !== $this->lastDebugData) {
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
		$config = $this->normalizeConfig($this->normalizeModelConfig($modelData['config']));

		return [
			'provider' => 'libretranslate',
			'model' => $this->normalizeString($modelData['key'] ?? null),
			'api' => $this->normalizeString($config['api'] ?? null),
			'apiUrl' => $this->normalizeString($config['apiUrl'] ?? null),
			'usage_supported' => false,
			'character' => null,
		];
	}

	public function getAvailableSourceLanguages(): array
	{
		return [
			'auto' => 'Automatic',
			'ar'   => 'Arabic (AR)',
			'az'   => 'Azerbaijani (AZ)',
			'bg'   => 'Bulgarian (BG)',
			'ca'   => 'Catalan (CA)',
			'cs'   => 'Czech (CS)',
			'cy'   => 'Welsh (CY)',
			'da'   => 'Danish (DA)',
			'de'   => 'German (DE)',
			'el'   => 'Greek (EL)',
			'en'   => 'English (EN)',
			'eo'   => 'Esperanto (EO)',
			'es'   => 'Spanish (ES)',
			'et'   => 'Estonian (ET)',
			'fa'   => 'Persian (FA)',
			'fi'   => 'Finnish (FI)',
			'fr'   => 'French (FR)',
			'ga'   => 'Irish (GA)',
			'gl'   => 'Galician (GL)',
			'he'   => 'Hebrew (HE)',
			'hi'   => 'Hindi (HI)',
			'hr'   => 'Croatian (HR)',
			'hu'   => 'Hungarian (HU)',
			'id'   => 'Indonesian (ID)',
			'it'   => 'Italian (IT)',
			'ja'   => 'Japanese (JA)',
			'ko'   => 'Korean (KO)',
			'lt'   => 'Lithuanian (LT)',
			'lv'   => 'Latvian (LV)',
			'mk'   => 'Macedonian (MK)',
			'ms'   => 'Malay (MS)',
			'mt'   => 'Maltese (MT)',
			'nb'   => 'Norwegian – Bokmål (NB)',
			'nl'   => 'Dutch (NL)',
			'pl'   => 'Polish (PL)',
			'pt'   => 'Portuguese (PT)',
			'ro'   => 'Romanian (RO)',
			'ru'   => 'Russian (RU)',
			'sk'   => 'Slovak (SK)',
			'sl'   => 'Slovenian (SL)',
			'sq'   => 'Albanian (SQ)',
			'sr'   => 'Serbian (SR)',
			'sv'   => 'Swedish (SV)',
			'th'   => 'Thai (TH)',
			'tr'   => 'Turkish (TR)',
			'uk'   => 'Ukrainian (UK)',
			'zh'   => 'Chinese (ZH)',
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

	/**
	 * @param array<string, mixed> $modelConfig
	 * @return array<string, mixed>
	 */
	private function normalizeConfig(array $modelConfig): array
	{
		$api = trim($this->normalizeString($modelConfig['api'] ?? null));
		$apiUrl = trim($this->normalizeString($modelConfig['apiUrl'] ?? 'https://libretranslate.com'));
		$apiKey = trim($this->normalizeString($modelConfig['apiKey'] ?? null));
		$timeout = $this->normalizeInt($modelConfig['timeout'] ?? null, 60);
		$timeout = max(1, min($timeout, 300));

		if ('' === $apiUrl) {
			throw new rex_exception('LibreTranslate requires an apiUrl in the model configuration.');
		}

		return [
			'api' => $api,
			'apiUrl' => $apiUrl,
			'translateUrl' => $this->buildTranslateUrl($apiUrl),
			'apiKey' => $apiKey,
			'timeout' => $timeout,
		];
	}

	private function buildTranslateUrl(string $apiUrl): string
	{
		$apiUrl = rtrim(trim($apiUrl), '/');
		if (preg_match('~/translate$~i', $apiUrl)) {
			return $apiUrl;
		}

		return $apiUrl . '/translate';
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function sendTranslateRequest(Client $client, string $translateUrl, array $payload): array
	{
		try {
			$response = $client->post($translateUrl, [
				'json' => $payload,
				'headers' => [
					'Accept' => 'application/json',
				],
			]);
		} catch (ConnectException $e) {
			throw new rex_exception(
				'LibreTranslate request timed out or connection failed. Check apiUrl and timeout.',
				$e
			);
		} catch (GuzzleException $e) {
			$message = $e->getMessage();
			$response = null;
			if (method_exists($e, 'getResponse')) {
				$response = $e->getResponse();
			}
			if ($response instanceof \Psr\Http\Message\ResponseInterface) {
				$status = $response->getStatusCode();
				$body = (string) $response->getBody();
				if ('' !== $body) {
					$decoded = json_decode($body, true);
					$apiMessage = '';
					if (is_array($decoded)) {
						$apiMessage = $this->normalizeString($decoded['error'] ?? null);
					}
					if ('' !== $apiMessage) {
						$message = $apiMessage;
					}
				}
				$message .= ' (HTTP ' . $status . ')';
			}

			throw new rex_exception('LibreTranslate request failed: ' . $message, new \RuntimeException($e->getMessage(), 0, $e));
		}

		$decodedBody = json_decode((string) $response->getBody(), true);
		$data = $this->normalizeJsonArray($decodedBody);
		if ([] === $data) {
			throw new rex_exception('LibreTranslate returned an invalid JSON response.');
		}

		return $data;
	}

	private function normalizeLanguageCode(string $lang): string
	{
		$lang = strtolower(str_replace('_', '-', trim($lang)));
		if ('' === $lang) {
			return $lang;
		}

		$parts = explode('-', $lang, 2);

		return $parts[0];
	}

	/** @return array<string, mixed> */
	private function normalizeJsonArray(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		$normalized = [];
		foreach ($value as $key => $item) {
			$normalized[is_string($key) ? $key : (string) $key] = $item;
		}

		return $normalized;
	}

	/** @return array<string, mixed> */
	public function getLastDebugData(): array
	{
		return $this->lastDebugData;
	}

	/**
	 * @param array<string, mixed> $transaction
	 * @return array<string, mixed>
	 */
	private function buildDebugData(array $transaction): array
	{
		/** @var \Psr\Http\Message\RequestInterface $req */
		$req = $transaction['request'];
		/** @var \Psr\Http\Message\ResponseInterface|null $res */
		$res = $transaction['response'] ?? null;

		$body = $this->normalizeJsonArray(json_decode((string) $req->getBody(), true));
		if (isset($body['api_key'])) {
			$body['api_key'] = '***';
		}

		return [
			'request' => [
				'method' => $req->getMethod(),
				'uri' => (string) $req->getUri(),
				'headers' => $req->getHeaders(),
				'body' => $body,
			],
			'response' => null !== $res ? [
				'status' => $res->getStatusCode(),
				'headers' => $res->getHeaders(),
				'body' => $this->normalizeJsonArray(json_decode((string) $res->getBody(), true)),
			] : null,
		];
	}

	public function getProviderLabel(): string
	{
		return 'LibreTranslate';
	}

	/** @return list<string> */
	public function getApiIdentifiers(): array
	{
		return ['libretranslate-v1'];
	}

	public function getConfigFields(): array
	{
		return [
			'api_url' => ['type' => 'text', 'label' => 'API URL', 'required' => true, 'column' => true, 'default' => 'https://libretranslate.com'],
			'api_key' => ['type' => 'text', 'label' => 'API Key', 'required' => false, 'column' => true],
			'timeout' => ['type' => 'number', 'label' => 'Timeout (s)', 'required' => false, 'column' => true, 'default' => 60],
		];
	}

	/** @param array<string, mixed> $values @return array<string, string> */
	public function validateConfig(array $values): array
	{
		$errors = [];
		if (empty(trim($this->normalizeString($values['api_url'] ?? null)))) {
			$errors['api_url'] = 'API URL is required.';
		}
		return $errors;
	}
}
