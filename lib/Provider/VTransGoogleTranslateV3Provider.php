<?php

namespace FriendsOfRedaxo\VTrans\Provider;

use FriendsOfRedaxo\VTrans\VTransProviderInterface;
use FriendsOfRedaxo\VTrans\VTransProviderResult;
use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use rex_exception;
use rex_path;

/**
 * Google Translate v3 provider (service account / OAuth flow).
 */
class VTransGoogleTranslateV3Provider implements VTransProviderInterface
{
	private const AUTH_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
	private const API_URL = 'https://translate.googleapis.com';
	/** @var array<string, mixed> */
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'google-translate-v3' === $api;
	}

	/**
	 * @param array<string, mixed> $modelData
	 * @param array<string, mixed> $requestOptions
	 */
	public function translate(string $text, ?string $srcLang, string $targetLang, string $format, array $modelData, array $requestOptions = []): VTransProviderResult
	{
		$promptContext = $this->normalizeString($requestOptions['promptContext'] ?? null);
		$debug = !empty($requestOptions['debug']);
		$config = $this->normalizeConfig($this->normalizeModelConfig($modelData['config']));
		$history = [];
		$stack = HandlerStack::create();
		$stack->push(Middleware::history($history));
		$client = new Client([
			'base_uri' => self::API_URL . '/',
			'timeout' => $config['timeout'],
			'handler' => $stack,
		]);
		$accessToken = $this->fetchAccessToken($this->normalizeString($config['credentialsFile'] ?? null));

		$payload = [
			'contents' => [(string) $text],
			'mimeType' => 'html' === $format ? 'text/html' : 'text/plain',
			'targetLanguageCode' => $this->normalizeLanguageCode($targetLang),
		];

		if (null !== $srcLang && '' !== trim($srcLang)) {
			$payload['sourceLanguageCode'] = $this->normalizeLanguageCode($srcLang);
		}

		$model = $this->buildModelName($config);
		if ('' !== $model) {
			$payload['model'] = $model;
		}

		if ([] !== $config['glossaryConfig']) {
			$payload['glossaryConfig'] = $config['glossaryConfig'];
		}

		try {
			$responseData = $this->sendTranslateRequest($client, $accessToken, $config, $payload);
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
		$translations = is_array($responseData['translations'] ?? null) ? $responseData['translations'] : [];
		$firstTranslation = [] !== $translations && isset($translations[0]) && is_array($translations[0]) ? $translations[0] : [];
		$translation = $this->normalizeString($firstTranslation['translatedText'] ?? null);

		if ('' === $translation) {
			throw new rex_exception('Google Translate v3 returned an empty translation response.');
		}

		$resultData = [
			'model' => $this->normalizeString($modelData['key'] ?? null),
			'api' => $this->normalizeString($config['api'] ?? null),
			'apiUrl' => self::API_URL,
			'projectId' => $config['projectId'],
			'location' => $config['location'],
			'googleModel' => '' !== $model ? $model : null,
			'glossaryConfig' => [] !== $config['glossaryConfig'] ? $config['glossaryConfig'] : null,
			'detected_source_language' => $firstTranslation['detectedLanguageCode'] ?? null,
			'format' => $format,
			'context' => '' !== $promptContext ? $promptContext : null,
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
			'provider' => 'google',
			'model' => $this->normalizeString($modelData['key'] ?? null),
			'api' => $this->normalizeString($config['api'] ?? null),
			'apiUrl' => self::API_URL,
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
		$projectId = trim($this->normalizeString($modelConfig['projectId'] ?? null));
		$location = trim($this->normalizeString($modelConfig['location'] ?? 'global'));
		$credentialsFile = trim($this->normalizeString($modelConfig['credentialsFile'] ?? null));
		$googleModel = trim($this->normalizeString($modelConfig['googleModel'] ?? null));
		$location = '' !== $location ? $location : 'global';

		if ('' === $credentialsFile) {
			throw new rex_exception('Google Translate v3 requires a credentialsFile path in the model configuration.');
		}

		$credentialsFile = $this->resolveCredentialsFile($credentialsFile);
		if (!is_file($credentialsFile)) {
			throw new rex_exception('Google Translate credentials file not found: ' . $credentialsFile);
		}

		// If projectId was not set explicitly, read project_id directly from the credentials JSON.
		if ('' === $projectId) {
			$credentialsJson = $this->readCredentialsJson($credentialsFile);
			$projectId = trim($this->normalizeString($credentialsJson['project_id'] ?? null));
		}

		if ('' === $projectId) {
			throw new rex_exception('Google Translate v3: projectId could not be determined. Set it in the model config or ensure the credentials JSON contains a project_id field.');
		}

		$glossaryConfig = $this->normalizeGlossaryConfig($modelConfig['glossaryConfig'] ?? [], $projectId, $location);

		$timeout = $this->normalizeInt($modelConfig['timeout'] ?? null, 30);
		$timeout = max(1, min($timeout, 300));

		return [
			'api' => $api,
			'projectId' => $projectId,
			'location' => $location,
			'credentialsFile' => $credentialsFile,
			'googleModel' => $googleModel,
			'glossaryConfig' => $glossaryConfig,
			'timeout' => $timeout,
		];
	}

	/** @return array<string, mixed> */
	private function normalizeGlossaryConfig(mixed $value, string $projectId, string $location): array
	{
		if (is_string($value)) {
			$glossary = trim($value);
			if ('' === $glossary) {
				return [];
			}

			return [
				'glossary' => $this->buildGlossaryName($glossary, $projectId, $location),
			];
		}

		if (!is_array($value)) {
			return [];
		}

		$glossary = trim($this->normalizeString($value['glossary'] ?? $value['name'] ?? null));
		if ('' === $glossary) {
			return [];
		}

		$config = [
			'glossary' => $this->buildGlossaryName($glossary, $projectId, $location),
		];

		if (array_key_exists('ignoreCase', $value)) {
			$config['ignoreCase'] = (bool) $value['ignoreCase'];
		}

		return $config;
	}

	private function buildGlossaryName(string $glossary, string $projectId, string $location): string
	{
		if (str_starts_with($glossary, 'projects/')) {
			return $glossary;
		}

		return sprintf(
			'projects/%s/locations/%s/glossaries/%s',
			$projectId,
			$location,
			ltrim($glossary, '/')
		);
	}

	/** @return array<string, mixed> */
	private function readCredentialsJson(string $credentialsFile): array
	{
		$content = file_get_contents($credentialsFile);
		if (false === $content) {
			return [];
		}

		return $this->normalizeJsonArray(json_decode($content, true));
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

	private function resolveCredentialsFile(string $credentialsFile): string
	{
		// Absolute paths are used as-is.
		if (str_starts_with($credentialsFile, '/') || preg_match('/^[A-Za-z]:\\\\/', $credentialsFile)) {
			return $credentialsFile;
		}

		// If a relative path was provided, first try the addon data folder
		// (e.g. data/addons/vtrans/<credentialsFile>), then fall back to
		// resolving against the REDAXO base path.
		$candidate = rex_path::addonData('vtrans', $credentialsFile);
		if (is_file($candidate)) {
			return $candidate;
		}

		return rex_path::base($credentialsFile);
	}

	private function fetchAccessToken(string $credentialsFile): string
	{
		$credentials = new ServiceAccountCredentials(self::AUTH_SCOPE, $credentialsFile);
		$tokenData = $credentials->fetchAuthToken();

		$accessToken = $this->normalizeString($tokenData['access_token'] ?? null);
		if ('' === $accessToken) {
			throw new rex_exception('Could not fetch Google access token from service-account credentials.');
		}

		return $accessToken;
	}

	/**
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function sendTranslateRequest(Client $client, string $accessToken, array $config, array $payload): array
	{
		$path = sprintf(
			'v3/projects/%s/locations/%s:translateText',
			rawurlencode($this->normalizeString($config['projectId'] ?? null)),
			rawurlencode($this->normalizeString($config['location'] ?? null))
		);

		try {
			$response = $client->post($path, [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'Accept' => 'application/json',
				],
				'json' => $payload,
			]);
		} catch (GuzzleException $e) {
			$message = $e->getMessage();
			$response = null;
			if (method_exists($e, 'getResponse')) {
				$response = $e->getResponse();
			}
			if ($response instanceof \Psr\Http\Message\ResponseInterface) {
				$body = (string) $response->getBody();
				if ('' !== $body) {
					$decodedBody = json_decode($body, true);
					$apiMessage = '';
					if (is_array($decodedBody) && isset($decodedBody['error']) && is_array($decodedBody['error'])) {
						$apiMessage = $this->normalizeString($decodedBody['error']['message'] ?? null);
					}
					if ('' !== $apiMessage) {
						$message = $apiMessage;
					}
				}
			}

			throw new rex_exception('Google Translate v3 request failed: ' . $message, new \RuntimeException($e->getMessage(), 0, $e));
		}

		$decodedBody = json_decode((string) $response->getBody(), true);
		$data = $this->normalizeJsonArray($decodedBody);
		if ([] === $data) {
			throw new rex_exception('Google Translate v3 returned an invalid JSON response.');
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function buildModelName(array $config): string
	{
		$googleModel = trim($this->normalizeString($config['googleModel'] ?? null));
		if ('' === $googleModel) {
			return '';
		}

		if (str_starts_with($googleModel, 'projects/')) {
			return $googleModel;
		}

		return sprintf(
			'projects/%s/locations/%s/models/%s',
			$this->normalizeString($config['projectId'] ?? null),
			$this->normalizeString($config['location'] ?? null),
			ltrim($googleModel, '/')
		);
	}

	private function normalizeLanguageCode(string $lang): string
	{
		$lang = trim($lang);
		if ('' === $lang) {
			return $lang;
		}

		$parts = explode('-', str_replace('_', '-', $lang));

		return implode('-', array_map(
			static fn(string $part, int $i): string => 0 === $i
				? strtolower($part)
				: (strlen($part) <= 3 ? strtoupper($part) : ucfirst(strtolower($part))),
			$parts,
			array_keys($parts),
		));
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

		$requestHeaders = $req->getHeaders();
		if (isset($requestHeaders['Authorization'])) {
			$requestHeaders['Authorization'] = ['Bearer ***'];
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
		return 'Google Translate v3';
	}

	/** @return list<string> */
	public function getApiIdentifiers(): array
	{
		return ['google-translate-v3'];
	}

	public function getConfigFields(): array
	{
		return [
			'credentialsFile' => ['type' => 'text', 'label' => 'Credentials File', 'required' => true, 'note' => 'Path to JSON service account file (relative to addon data dir or absolute).'],
			'projectId' => ['type' => 'text', 'label' => 'Project ID', 'required' => false, 'note' => 'Wird aus Credentials-Datei gelesen, falls leer.'],
			'location' => ['type' => 'text', 'label' => 'Location', 'required' => false, 'default' => 'global'],
			'googleModel' => ['type' => 'text', 'label' => 'Google Model', 'required' => false, 'note' => 'z. B. general/nmt, general/translation-llm'],
			'glossaryConfig' => ['type' => 'textarea', 'label' => 'Glossary Config (JSON)', 'required' => false, 'note' => 'JSON object for glossary configuration.'],
		];
	}

	/** @param array<string, mixed> $values @return array<string, string> */
	public function validateConfig(array $values): array
	{
		$errors = [];
		if (empty(trim($this->normalizeString($values['credentialsFile'] ?? null)))) {
			$errors['credentialsFile'] = 'Credentials file is required.';
		}
		return $errors;
	}
}