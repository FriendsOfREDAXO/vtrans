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
 * Google Translate Basic v2 provider (API key based).
 */
class VTransGoogleTranslateBasicV2Provider implements VTransProviderInterface
{
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'google-translate-basic-v2' === $api;
	}

	public function translate(string $text, ?string $srcLang, string $targetLang, string $format, array $modelData, array $requestOptions = []): VTransProviderResult
	{
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

		$query = [
			'key' => $config['apiKey'],
			'q' => (string) $text,
			'target' => $this->normalizeLanguageCode($targetLang),
			'format' => 'html' === $format ? 'html' : 'text',
			'model' => 'nmt',
		];

		if (null !== $srcLang && '' !== trim($srcLang)) {
			$query['source'] = $this->normalizeLanguageCode($srcLang);
		}

		try {
			$data = $this->sendTranslateRequest($client, $query);
		} finally {
			if (!empty($history)) {
				$this->lastDebugData = $this->buildDebugData($history[0]);
			}
		}
		$translation = (string) ($data['data']['translations'][0]['translatedText'] ?? '');

		if ('' === $translation) {
			throw new rex_exception('Google Translate Basic v2 returned an empty translation response.');
		}

		$resultData = [
			'model' => $modelData['key'],
			'api' => $config['api'],
			'apiUrl' => $config['apiUrl'],
			'detected_source_language' => $data['data']['translations'][0]['detectedSourceLanguage'] ?? null,
			'provider_model' => $data['data']['translations'][0]['model'] ?? 'nmt',
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
			'provider' => 'google-basic-v2',
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
			'ka'    => 'Georgian (KA)',
			'km'    => 'Khmer (KM)',
			'kn'    => 'Kannada (KN)',
			'ko'    => 'Korean (KO)',
			'lt'    => 'Lithuanian (LT)',
			'lv'    => 'Latvian (LV)',
			'mk'    => 'Macedonian (MK)',
			'ml'    => 'Malayalam (ML)',
			'mr'    => 'Marathi (MR)',
			'ms'    => 'Malay (MS)',
			'mt'    => 'Maltese (MT)',
			'my'    => 'Myanmar / Burmese (MY)',
			'nb'    => 'Norwegian – Bokmål (NB)',
			'ne'    => 'Nepali (NE)',
			'nl'    => 'Dutch (NL)',
			'pa'    => 'Punjabi (PA)',
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
			'uz'    => 'Uzbek (UZ)',
			'vi'    => 'Vietnamese (VI)',
			'zh-cn' => 'Chinese – Simplified (ZH-CN)',
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

	private function normalizeConfig(array $modelConfig): array
	{
		$api = trim((string) ($modelConfig['api'] ?? ''));
		$apiUrl = trim((string) ($modelConfig['apiUrl'] ?? 'https://translation.googleapis.com/language/translate/v2'));
		$apiKey = trim((string) ($modelConfig['apiKey'] ?? ''));

		if ('' === $apiKey) {
			throw new rex_exception('Google Translate Basic v2 requires an apiKey in the model configuration.');
		}

		$timeout = (int) ($modelConfig['timeout'] ?? 30);
		$timeout = max(1, min($timeout, 300));

		return [
			'api' => $api,
			'apiUrl' => $apiUrl,
			'apiKey' => $apiKey,
			'timeout' => $timeout,
		];
	}

	private function sendTranslateRequest(Client $client, array $query): array
	{
		try {
			$bodyParams = $query;
			$queryParams = [];

			if (isset($bodyParams['key'])) {
				$queryParams['key'] = (string) $bodyParams['key'];
				unset($bodyParams['key']);
			}

			$response = $client->post('', [
				'query' => $queryParams,
				'form_params' => $bodyParams,
				'headers' => [
					'Accept' => 'application/json',
				],
			]);
		} catch (GuzzleException $e) {
			$message = $e->getMessage();
			if (method_exists($e, 'getResponse') && null !== $e->getResponse()) {
				$body = (string) $e->getResponse()->getBody();
				if ('' !== $body) {
					$decodedBody = json_decode($body, true);
					$apiMessage = (string) ($decodedBody['error']['message'] ?? '');
					if ('' !== $apiMessage) {
						$message = $apiMessage;
					}
				}
			}

			throw new rex_exception('Google Translate Basic v2 request failed: ' . $message, $e);
		}

		$data = json_decode((string) $response->getBody(), true);
		if (!is_array($data)) {
			throw new rex_exception('Google Translate Basic v2 returned an invalid JSON response.');
		}

		return $data;
	}

	private function normalizeLanguageCode(string $lang): string
	{
		return str_replace('_', '-', strtolower(trim($lang)));
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

		$safeQuery = [];
		parse_str($req->getUri()->getQuery(), $safeQuery);
		if (isset($safeQuery['key'])) {
			$safeQuery['key'] = '***';
		}

		$safeBody = [];
		parse_str((string) $req->getBody(), $safeBody);
		if (isset($safeBody['key'])) {
			$safeBody['key'] = '***';
		}

		return [
			'request' => [
				'method' => $req->getMethod(),
				'uri' => (string) $req->getUri()->withQuery(''),
				'query' => $safeQuery,
				'body' => $safeBody,
				'headers' => $req->getHeaders(),
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
		return 'Google Translate Basic v2';
	}

	public function getApiIdentifiers(): array
	{
		return ['google-translate-basic-v2'];
	}

	public function getConfigFields(): array
	{
		return [
			'api_key' => ['type' => 'text', 'label' => 'API Key', 'required' => true, 'column' => true],
			'api_url' => ['type' => 'text', 'label' => 'API URL', 'required' => false, 'column' => true, 'default' => 'https://translation.googleapis.com/language/translate/v2'],
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
}