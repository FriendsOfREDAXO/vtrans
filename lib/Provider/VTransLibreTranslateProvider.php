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
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'libretranslate-v1' === $api;
	}

	public function translate(string $text, ?string $srcLang, string $targetLang, string $format, array $modelData, array $requestOptions = []): VTransProviderResult
	{
		$debug = !empty($requestOptions['debug']);
		$config = $this->normalizeConfig($modelData['config']);
		$history = [];
		$stack = HandlerStack::create();
		$stack->push(Middleware::history($history));
		$client = new Client([
			'timeout' => $config['timeout'],
			'handler' => $stack,
		]);

		$payload = [
			'q' => (string) $text,
			'source' => null !== $srcLang && '' !== trim($srcLang) ? $this->normalizeLanguageCode($srcLang) : 'auto',
			'target' => $this->normalizeLanguageCode($targetLang),
			'format' => 'html' === $format ? 'html' : 'text',
		];

		if ('' !== $config['apiKey']) {
			$payload['api_key'] = $config['apiKey'];
		}

		try {
			$data = $this->sendTranslateRequest($client, $config['translateUrl'], $payload);
		} finally {
			if (!empty($history)) {
				$this->lastDebugData = $this->buildDebugData($history[0]);
			}
		}
		$translation = (string) ($data['translatedText'] ?? '');

		if ('' === $translation) {
			throw new rex_exception('LibreTranslate returned an empty translation response.');
		}

		$resultData = [
			'model' => $modelData['key'],
			'api' => $config['api'],
			'apiUrl' => $config['apiUrl'],
			'detected_source_language' => $data['detectedLanguage']['language'] ?? null,
			'format' => $format,
		];

		if ($debug && [] !== $this->lastDebugData) {
			$resultData['_debug'] = $this->lastDebugData;
		}

		return new VTransProviderResult($translation, $resultData);
	}

	public function getUsage(array $modelData): array
	{
		$config = $this->normalizeConfig($modelData['config']);

		return [
			'provider' => 'libretranslate',
			'model' => (string) ($modelData['key'] ?? ''),
			'api' => $config['api'],
			'apiUrl' => $config['apiUrl'],
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

	private function normalizeConfig(array $modelConfig): array
	{
		$api = trim((string) ($modelConfig['api'] ?? ''));
		$apiUrl = trim((string) ($modelConfig['apiUrl'] ?? 'https://libretranslate.com'));
		$apiKey = trim((string) ($modelConfig['apiKey'] ?? ''));
		$timeout = (int) ($modelConfig['timeout'] ?? 60);
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
			if (method_exists($e, 'getResponse') && null !== $e->getResponse()) {
				$status = $e->getResponse()->getStatusCode();
				$body = (string) $e->getResponse()->getBody();
				if ('' !== $body) {
					$decoded = json_decode($body, true);
					$apiMessage = (string) ($decoded['error'] ?? '');
					if ('' !== $apiMessage) {
						$message = $apiMessage;
					}
				}
				$message .= ' (HTTP ' . $status . ')';
			}

			throw new rex_exception('LibreTranslate request failed: ' . $message, $e);
		}

		$data = json_decode((string) $response->getBody(), true);
		if (!is_array($data)) {
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

	public function getLastDebugData(): array
	{
		return $this->lastDebugData;
	}

	private function buildDebugData(array $transaction): array
	{
		/** @var \Psr\Http\Message\RequestInterface $req */
		$req = $transaction['request'];
		/** @var \Psr\Http\Message\ResponseInterface|null $res */
		$res = $transaction['response'] ?? null;

		$body = json_decode((string) $req->getBody(), true);
		if (is_array($body) && isset($body['api_key'])) {
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
				'body' => json_decode((string) $res->getBody(), true),
			] : null,
		];
	}

	public function getProviderLabel(): string
	{
		return 'LibreTranslate';
	}

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

	public function validateConfig(array $values): array
	{
		$errors = [];
		if (empty(trim((string) ($values['api_url'] ?? '')))) {
			$errors['api_url'] = 'API URL is required.';
		}
		return $errors;
	}
}
