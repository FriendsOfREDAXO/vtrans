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
 * MyMemory provider (https://mymemory.translated.net/).
 */
class VTransMyMemoryProvider implements VTransProviderInterface
{
	/** @var array<string, mixed> */
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'mymemory-v2' === $api;
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
		$requestUrl = $this->buildRequestUrl($this->normalizeString($config['apiUrl'] ?? null));
		$stack = HandlerStack::create();
		$stack->push(Middleware::history($history));
		$client = new Client([
			'timeout' => $config['timeout'],
			'handler' => $stack,
		]);

		$source = null !== $srcLang && '' !== trim($srcLang)
			? $this->normalizeLanguageCode($srcLang)
			: $config['sourceFallback'];
		$target = $this->normalizeLanguageCode($targetLang);

		$query = [
			'q' => $this->normalizeString($text),
			'langpair' => $this->normalizeString($source) . '|' . $this->normalizeString($target),
		];

		if ('' !== $config['apiKey']) {
			$query['key'] = $config['apiKey'];
		}
		if ('' !== $config['email']) {
			$query['de'] = $config['email'];
		}

		try {
			$data = $this->sendTranslateRequest($client, $requestUrl, $query);
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
		$responseData = is_array($data['responseData'] ?? null) ? $data['responseData'] : [];
		$translation = $this->normalizeString($responseData['translatedText'] ?? null);

		if ('' === $translation) {
			throw new rex_exception('MyMemory returned an empty translation response.');
		}

		$resultData = [
			'model' => $this->normalizeString($modelData['key'] ?? null),
			'api' => $this->normalizeString($config['api'] ?? null),
			'apiUrl' => $this->normalizeString($config['apiUrl'] ?? null),
			'requestUrl' => $requestUrl,
			'langpair' => $query['langpair'],
			'source_used' => $source,
			'source_fallback' => $this->normalizeString($config['sourceFallback'] ?? null),
			'match' => $responseData['match'] ?? null,
			'responseStatus' => $data['responseStatus'] ?? null,
			'responseDetails' => $data['responseDetails'] ?? null,
			'format' => $format,
		];

		if ($debug && [] !== $this->lastDebugData) {
			$resultData['_debug'] = $this->lastDebugData;
		}

		return new VTransProviderResult($translation, $resultData);
	}

	/** @param array<string, mixed> $modelData @return array<string, mixed> */
	public function getUsage(array $modelData): array
	{
		$config = $this->normalizeConfig($this->normalizeModelConfig($modelData['config']));

		return [
			'provider' => 'mymemory',
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
			'de' => 'German (DE)',
			'en' => 'English (EN)',
			'es' => 'Spanish (ES)',
			'fr' => 'French (FR)',
			'it' => 'Italian (IT)',
			'nl' => 'Dutch (NL)',
			'pl' => 'Polish (PL)',
			'pt' => 'Portuguese (PT)',
			'ru' => 'Russian (RU)',
			'tr' => 'Turkish (TR)',
			'uk' => 'Ukrainian (UK)',
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
		$apiUrl = trim($this->normalizeString($modelConfig['apiUrl'] ?? 'https://api.mymemory.translated.net/get'));
		$apiKey = trim($this->normalizeString($modelConfig['apiKey'] ?? null));
		$email = trim($this->normalizeString($modelConfig['email'] ?? null));
		$sourceFallback = $this->normalizeLanguageCode($this->normalizeString($modelConfig['sourceFallback'] ?? 'en'));
		$timeout = $this->normalizeInt($modelConfig['timeout'] ?? null, 30);
		$timeout = max(1, min($timeout, 300));

		if ('' === $apiUrl) {
			throw new rex_exception('MyMemory requires an apiUrl in the model configuration.');
		}

		return [
			'api' => $api,
			'apiUrl' => $apiUrl,
			'apiKey' => $apiKey,
			'email' => $email,
			'sourceFallback' => '' !== $sourceFallback ? $sourceFallback : 'en',
			'timeout' => $timeout,
		];
	}

	private function buildRequestUrl(string $apiUrl): string
	{
		$apiUrl = rtrim(trim($apiUrl), '/');
		if (preg_match('~/get$~i', $apiUrl)) {
			return $apiUrl;
		}

		return $apiUrl . '/get';
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	private function sendTranslateRequest(Client $client, string $requestUrl, array $query): array
	{
		try {
			$response = $client->post($requestUrl, [
				'form_params' => $query,
				'headers' => [
					'Accept' => 'application/json',
				],
			]);
		} catch (ConnectException $e) {
			throw new rex_exception('MyMemory request timed out or connection failed. Check apiUrl and timeout.', $e);
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
						$responseDetails = $decoded['responseDetails'] ?? $decoded['error'] ?? null;
						$apiMessage = is_string($responseDetails) ? $responseDetails : '';
					}
					if ('' !== $apiMessage) {
						$message = $apiMessage;
					}
				}
				$message .= ' (HTTP ' . $status . ')';
			}

			throw new rex_exception('MyMemory request failed: ' . $message, new \RuntimeException($e->getMessage(), 0, $e));
		}

		$decodedBody = json_decode((string) $response->getBody(), true);
		$data = $this->normalizeJsonArray($decodedBody);
		if ([] === $data) {
			throw new rex_exception('MyMemory returned an invalid JSON response.');
		}

		$responseStatus = $this->normalizeInt($data['responseStatus'] ?? null, 0);
		if (200 !== $responseStatus) {
			$message = trim($this->normalizeString($data['responseDetails'] ?? 'MyMemory returned an unexpected response status.'));
			throw new rex_exception('MyMemory request failed: ' . $message . ' (status ' . $responseStatus . ')');
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
		$req = $transaction['request'] ?? null;
		$res = $transaction['response'] ?? null;

		$safeBody = [];
		if ($req instanceof \Psr\Http\Message\RequestInterface) {
			parse_str((string) $req->getBody(), $safeBody);
		}
		if (isset($safeBody['key'])) {
			$safeBody['key'] = '***';
		}

		$responseData = null;
		if ($res instanceof \Psr\Http\Message\ResponseInterface) {
			$responseData = [
				'status' => $res->getStatusCode(),
				'headers' => $res->getHeaders(),
				'body' => $this->normalizeJsonArray(json_decode((string) $res->getBody(), true)),
			];
		}

		return [
			'request' => $req instanceof \Psr\Http\Message\RequestInterface ? [
				'method' => $req->getMethod(),
				'uri' => (string) $req->getUri(),
				'form_params' => $safeBody,
				'headers' => $req->getHeaders(),
			] : null,
			'response' => $responseData,
		];
	}

	public function getProviderLabel(): string
	{
		return 'MyMemory';
	}

	/** @return list<string> */
	public function getApiIdentifiers(): array
	{
		return ['mymemory-v2'];
	}

	public function getConfigFields(): array
	{
		return [
			'api_url' => ['type' => 'text', 'label' => 'API URL', 'required' => false, 'column' => true, 'default' => 'https://api.mymemory.translated.net/get'],
			'api_key' => ['type' => 'text', 'label' => 'API Key', 'required' => false, 'column' => true],
			'email' => ['type' => 'text', 'label' => 'E-Mail', 'required' => false, 'note' => 'Optional email for higher rate limits.'],
			'sourceFallback' => ['type' => 'text', 'label' => 'Source Fallback', 'required' => false, 'default' => 'en', 'note' => 'Fallback source language when auto-detect is not available.'],
			'timeout' => ['type' => 'number', 'label' => 'Timeout (s)', 'required' => false, 'column' => true, 'default' => 30],
		];
	}

	/** @param array<string, mixed> $values @return array<string, string> */
	public function validateConfig(array $values): array
	{
		return [];
	}
}
