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
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'mymemory-v2' === $api;
	}

	public function translate(string $text, ?string $srcLang, string $targetLang, string $format, array $modelData, array $requestOptions = []): VTransProviderResult
	{
		$debug = !empty($requestOptions['debug']);
		$config = $this->normalizeConfig($modelData['config']);
		$history = [];
		$requestUrl = $this->buildRequestUrl($config['apiUrl']);
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
			'q' => (string) $text,
			'langpair' => $source . '|' . $target,
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
				$this->lastDebugData = $this->buildDebugData($history[0]);
			}
		}
		$translation = (string) ($data['responseData']['translatedText'] ?? '');

		if ('' === $translation) {
			throw new rex_exception('MyMemory returned an empty translation response.');
		}

		$resultData = [
			'model' => $modelData['key'],
			'api' => $config['api'],
			'apiUrl' => $config['apiUrl'],
			'requestUrl' => $requestUrl,
			'langpair' => $query['langpair'],
			'source_used' => $source,
			'source_fallback' => $config['sourceFallback'],
			'match' => $data['responseData']['match'] ?? null,
			'responseStatus' => $data['responseStatus'] ?? null,
			'responseDetails' => $data['responseDetails'] ?? null,
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
			'provider' => 'mymemory',
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

	private function normalizeConfig(array $modelConfig): array
	{
		$api = trim((string) ($modelConfig['api'] ?? ''));
		$apiUrl = trim((string) ($modelConfig['apiUrl'] ?? 'https://api.mymemory.translated.net/get'));
		$apiKey = trim((string) ($modelConfig['apiKey'] ?? ''));
		$email = trim((string) ($modelConfig['email'] ?? ''));
		$sourceFallback = $this->normalizeLanguageCode((string) ($modelConfig['sourceFallback'] ?? 'en'));
		$timeout = (int) ($modelConfig['timeout'] ?? 30);
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
			if (method_exists($e, 'getResponse') && null !== $e->getResponse()) {
				$status = $e->getResponse()->getStatusCode();
				$body = (string) $e->getResponse()->getBody();
				if ('' !== $body) {
					$decoded = json_decode($body, true);
					$apiMessage = (string) ($decoded['responseDetails'] ?? $decoded['error'] ?? '');
					if ('' !== $apiMessage) {
						$message = $apiMessage;
					}
				}
				$message .= ' (HTTP ' . $status . ')';
			}

			throw new rex_exception('MyMemory request failed: ' . $message, $e);
		}

		$data = json_decode((string) $response->getBody(), true);
		if (!is_array($data)) {
			throw new rex_exception('MyMemory returned an invalid JSON response.');
		}

		$responseStatus = (int) ($data['responseStatus'] ?? 0);
		if (200 !== $responseStatus) {
			$message = trim((string) ($data['responseDetails'] ?? 'MyMemory returned an unexpected response status.'));
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

		$safeBody = [];
		parse_str((string) $req->getBody(), $safeBody);
		if (isset($safeBody['key'])) {
			$safeBody['key'] = '***';
		}

		return [
			'request' => [
				'method' => $req->getMethod(),
				'uri' => (string) $req->getUri(),
				'form_params' => $safeBody,
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
		return 'MyMemory';
	}

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

	public function validateConfig(array $values): array
	{
		return [];
	}
}
