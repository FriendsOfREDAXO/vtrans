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
 * Amazon Translate provider (AWS Signature Version 4).
 */
class VTransAmazonTranslateProvider implements VTransProviderInterface
{
	private const SERVICE = 'translate';
	private const TARGET = 'AWSShineFrontendService_20170701.TranslateText';
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'amazon-translate-v2' === $api;
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

		$payloadData = [
			'Text' => (string) $text,
			'TextType' => 'html' === strtolower(trim($format)) ? 'html' : 'text',
			'SourceLanguageCode' => null !== $srcLang && '' !== trim($srcLang)
				? $this->normalizeLanguageCode($srcLang)
				: 'auto',
			'TargetLanguageCode' => $this->normalizeLanguageCode($targetLang),
		];
		$payload = (string) json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$headers = $this->buildSignedHeaders('POST', $config['apiUrl'], $payload, $config);

		try {
			$data = $this->sendTranslateRequest($client, $config['apiUrl'], $headers, $payload);
		} finally {
			if (!empty($history)) {
				$this->lastDebugData = $this->buildDebugData($history[0]);
			}
		}

		$translation = (string) ($data['TranslatedText'] ?? '');
		if ('' === $translation) {
			throw new rex_exception('Amazon Translate returned an empty translation response.');
		}

		$resultData = [
			'model' => $modelData['key'],
			'api' => $config['api'],
			'apiUrl' => $config['apiUrl'],
			'region' => $config['region'],
			'source_language' => $data['SourceLanguageCode'] ?? $payloadData['SourceLanguageCode'],
			'target_language' => $data['TargetLanguageCode'] ?? $payloadData['TargetLanguageCode'],
			'text_type' => $payloadData['TextType'],
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
			'provider' => 'amazon-translate',
			'model' => (string) ($modelData['key'] ?? ''),
			'api' => $config['api'],
			'apiUrl' => $config['apiUrl'],
			'region' => $config['region'],
			'usage_supported' => false,
			'character' => null,
		];
	}

	public function getAvailableSourceLanguages(): array
	{
		return [
			'auto' => 'Automatic',
			'ar' => 'Arabic (AR)',
			'bg' => 'Bulgarian (BG)',
			'bn' => 'Bengali (BN)',
			'cs' => 'Czech (CS)',
			'da' => 'Danish (DA)',
			'de' => 'German (DE)',
			'el' => 'Greek (EL)',
			'en' => 'English (EN)',
			'es' => 'Spanish (ES)',
			'et' => 'Estonian (ET)',
			'fa' => 'Persian (FA)',
			'fi' => 'Finnish (FI)',
			'fr' => 'French (FR)',
			'he' => 'Hebrew (HE)',
			'hi' => 'Hindi (HI)',
			'hu' => 'Hungarian (HU)',
			'id' => 'Indonesian (ID)',
			'it' => 'Italian (IT)',
			'ja' => 'Japanese (JA)',
			'ko' => 'Korean (KO)',
			'lt' => 'Lithuanian (LT)',
			'lv' => 'Latvian (LV)',
			'nl' => 'Dutch (NL)',
			'no' => 'Norwegian (NO)',
			'pl' => 'Polish (PL)',
			'pt' => 'Portuguese (PT)',
			'ro' => 'Romanian (RO)',
			'ru' => 'Russian (RU)',
			'sk' => 'Slovak (SK)',
			'sl' => 'Slovenian (SL)',
			'sv' => 'Swedish (SV)',
			'tr' => 'Turkish (TR)',
			'uk' => 'Ukrainian (UK)',
			'vi' => 'Vietnamese (VI)',
			'zh' => 'Chinese (ZH)',
			'zh-TW' => 'Chinese Traditional (ZH-TW)',
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
		$region = trim((string) ($modelConfig['region'] ?? ''));
		$accessKey = trim((string) ($modelConfig['accessKey'] ?? ''));
		$secretKey = trim((string) ($modelConfig['secretKey'] ?? ''));
		$sessionToken = trim((string) ($modelConfig['sessionToken'] ?? ''));
		$apiUrl = trim((string) ($modelConfig['apiUrl'] ?? ''));
		$timeout = (int) ($modelConfig['timeout'] ?? 30);
		$timeout = max(1, min($timeout, 300));

		if ('' === $region) {
			throw new rex_exception('Amazon Translate requires a region in the model configuration.');
		}
		if ('' === $accessKey || '' === $secretKey) {
			throw new rex_exception('Amazon Translate requires accessKey and secretKey in the model configuration.');
		}

		if ('' === $apiUrl) {
			$apiUrl = 'https://translate.' . $region . '.amazonaws.com/';
		}

		return [
			'api' => $api,
			'region' => $region,
			'accessKey' => $accessKey,
			'secretKey' => $secretKey,
			'sessionToken' => $sessionToken,
			'apiUrl' => $apiUrl,
			'timeout' => $timeout,
		];
	}

	private function sendTranslateRequest(Client $client, string $apiUrl, array $headers, string $payload): array
	{
		try {
			$response = $client->post($apiUrl, [
				'headers' => $headers,
				'body' => $payload,
			]);
		} catch (ConnectException $e) {
			throw new rex_exception('Amazon Translate request timed out or connection failed. Check apiUrl and timeout.', $e);
		} catch (GuzzleException $e) {
			$message = $e->getMessage();
			if (method_exists($e, 'getResponse') && null !== $e->getResponse()) {
				$status = $e->getResponse()->getStatusCode();
				$body = (string) $e->getResponse()->getBody();
				if ('' !== $body) {
					$decodedBody = json_decode($body, true);
					$apiMessage = (string) ($decodedBody['message'] ?? $decodedBody['Message'] ?? '');
					if ('' !== $apiMessage) {
						$message = $apiMessage;
					}
				}
				$message .= ' (HTTP ' . $status . ')';
			}

			throw new rex_exception('Amazon Translate request failed: ' . $message, $e);
		}

		$data = json_decode((string) $response->getBody(), true);
		if (!is_array($data)) {
			throw new rex_exception('Amazon Translate returned an invalid JSON response.');
		}

		if (isset($data['__type']) || isset($data['message'])) {
			$message = trim((string) ($data['message'] ?? $data['Message'] ?? 'Amazon Translate returned an error response.'));
			throw new rex_exception('Amazon Translate request failed: ' . $message);
		}

		return $data;
	}

	private function buildSignedHeaders(string $method, string $url, string $payload, array $config): array
	{
		$timestamp = gmdate('Ymd\\THis\\Z');
		$date = gmdate('Ymd');
		$payloadHash = hash('sha256', $payload);

		$parts = parse_url($url);
		$host = (string) ($parts['host'] ?? '');
		$path = (string) ($parts['path'] ?? '/');
		if ('' === $path) {
			$path = '/';
		}

		$queryString = '';
		if (isset($parts['query']) && '' !== $parts['query']) {
			parse_str($parts['query'], $queryParams);
			ksort($queryParams);
			$queryItems = [];
			foreach ($queryParams as $key => $value) {
				$queryItems[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
			}
			$queryString = implode('&', $queryItems);
		}

		$headers = [
			'content-type' => 'application/x-amz-json-1.1',
			'host' => $host,
			'x-amz-content-sha256' => $payloadHash,
			'x-amz-date' => $timestamp,
			'x-amz-target' => self::TARGET,
		];
		if ('' !== $config['sessionToken']) {
			$headers['x-amz-security-token'] = $config['sessionToken'];
		}

		ksort($headers);
		$signedHeaders = implode(';', array_keys($headers));

		$canonicalHeaders = '';
		foreach ($headers as $name => $value) {
			$canonicalHeaders .= $name . ':' . trim((string) $value) . "\n";
		}

		$canonicalRequest = implode("\n", [
			$method,
			$path,
			$queryString,
			$canonicalHeaders,
			$signedHeaders,
			$payloadHash,
		]);

		$scope = $date . '/' . $config['region'] . '/' . self::SERVICE . '/aws4_request';
		$stringToSign = implode("\n", [
			'AWS4-HMAC-SHA256',
			$timestamp,
			$scope,
			hash('sha256', $canonicalRequest),
		]);

		$kDate = hash_hmac('sha256', $date, 'AWS4' . $config['secretKey'], true);
		$kRegion = hash_hmac('sha256', $config['region'], $kDate, true);
		$kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
		$kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
		$signature = hash_hmac('sha256', $stringToSign, $kSigning);

		$authorization = 'AWS4-HMAC-SHA256 '
			. 'Credential=' . $config['accessKey'] . '/' . $scope . ', '
			. 'SignedHeaders=' . $signedHeaders . ', '
			. 'Signature=' . $signature;

		$finalHeaders = [
			'Content-Type' => $headers['content-type'],
			'Host' => $headers['host'],
			'X-Amz-Content-Sha256' => $headers['x-amz-content-sha256'],
			'X-Amz-Date' => $headers['x-amz-date'],
			'X-Amz-Target' => $headers['x-amz-target'],
			'Authorization' => $authorization,
			'Accept' => 'application/json',
		];

		if (isset($headers['x-amz-security-token'])) {
			$finalHeaders['X-Amz-Security-Token'] = $headers['x-amz-security-token'];
		}

		return $finalHeaders;
	}

	private function normalizeLanguageCode(string $lang): string
	{
		$lang = str_replace('_', '-', trim($lang));
		if ('' === $lang) {
			return $lang;
		}

		$parts = explode('-', $lang);
		$normalizedParts = [];
		foreach ($parts as $index => $part) {
			$normalizedParts[] = 0 === $index ? strtolower($part) : strtoupper($part);
		}

		return implode('-', $normalizedParts);
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

		$requestHeaders = $req->getHeaders();
		if (isset($requestHeaders['Authorization'])) {
			$requestHeaders['Authorization'] = ['AWS4-HMAC-SHA256 ***'];
		}
		if (isset($requestHeaders['X-Amz-Security-Token'])) {
			$requestHeaders['X-Amz-Security-Token'] = ['***'];
		}

		return [
			'request' => [
				'method' => $req->getMethod(),
				'uri' => (string) $req->getUri(),
				'headers' => $requestHeaders,
				'body' => json_decode((string) $req->getBody(), true),
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
		return 'Amazon Translate';
	}

	public function getApiIdentifiers(): array
	{
		return ['amazon-translate-v2'];
	}

	public function getConfigFields(): array
	{
		return [
			'region' => ['type' => 'text', 'label' => 'AWS Region', 'required' => true, 'note' => 'z. B. eu-central-1'],
			'accessKey' => ['type' => 'text', 'label' => 'Access Key', 'required' => true],
			'secretKey' => ['type' => 'text', 'label' => 'Secret Key', 'required' => true],
			'sessionToken' => ['type' => 'text', 'label' => 'Session Token', 'required' => false],
			'api_url' => ['type' => 'text', 'label' => 'API URL', 'required' => false, 'column' => true, 'note' => 'Leer lassen fuer Standard-Endpoint.'],
			'timeout' => ['type' => 'number', 'label' => 'Timeout (s)', 'required' => false, 'column' => true, 'default' => 30],
		];
	}

	public function validateConfig(array $values): array
	{
		$errors = [];
		if (empty(trim((string) ($values['region'] ?? '')))) {
			$errors['region'] = 'AWS Region is required.';
		}
		if (empty(trim((string) ($values['accessKey'] ?? '')))) {
			$errors['accessKey'] = 'Access Key is required.';
		}
		if (empty(trim((string) ($values['secretKey'] ?? '')))) {
			$errors['secretKey'] = 'Secret Key is required.';
		}
		return $errors;
	}
}
