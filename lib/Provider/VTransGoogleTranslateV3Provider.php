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
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'google-translate-v3' === $api;
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
			'base_uri' => self::API_URL . '/',
			'timeout' => $config['timeout'],
			'handler' => $stack,
		]);
		$accessToken = $this->fetchAccessToken($config['credentialsFile']);

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
				$this->lastDebugData = $this->buildDebugData($history[0]);
			}
		}
		$translation = (string) ($responseData['translations'][0]['translatedText'] ?? '');

		if ('' === $translation) {
			throw new rex_exception('Google Translate v3 returned an empty translation response.');
		}

		$resultData = [
			'model' => $modelData['key'],
			'api' => $config['api'],
			'apiUrl' => self::API_URL,
			'projectId' => $config['projectId'],
			'location' => $config['location'],
			'googleModel' => '' !== $model ? $model : null,
			'glossaryConfig' => [] !== $config['glossaryConfig'] ? $config['glossaryConfig'] : null,
			'detected_source_language' => $responseData['translations'][0]['detectedLanguageCode'] ?? null,
			'format' => $format,
			'context' => '' !== $promptContext ? $promptContext : null,
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
			'provider' => 'google',
			'model' => (string) ($modelData['key'] ?? ''),
			'api' => $config['api'],
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

	private function normalizeConfig(array $modelConfig): array
	{
		$api = trim((string) ($modelConfig['api'] ?? ''));
		$projectId = trim((string) ($modelConfig['projectId'] ?? ''));
		$location = trim((string) ($modelConfig['location'] ?? 'global'));
		$credentialsFile = trim((string) ($modelConfig['credentialsFile'] ?? ''));
		$googleModel = trim((string) ($modelConfig['googleModel'] ?? ''));
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
			$projectId = trim((string) ($credentialsJson['project_id'] ?? ''));
		}

		if ('' === $projectId) {
			throw new rex_exception('Google Translate v3: projectId could not be determined. Set it in the model config or ensure the credentials JSON contains a project_id field.');
		}

		$glossaryConfig = $this->normalizeGlossaryConfig($modelConfig['glossaryConfig'] ?? [], $projectId, $location);

		$timeout = (int) ($modelConfig['timeout'] ?? 30);
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

		$glossary = trim((string) ($value['glossary'] ?? $value['name'] ?? ''));
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

	private function readCredentialsJson(string $credentialsFile): array
	{
		$content = file_get_contents($credentialsFile);
		if (false === $content) {
			return [];
		}

		return json_decode($content, true) ?: [];
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

		$accessToken = (string) ($tokenData['access_token'] ?? '');
		if ('' === $accessToken) {
			throw new rex_exception('Could not fetch Google access token from service-account credentials.');
		}

		return $accessToken;
	}

	private function sendTranslateRequest(Client $client, string $accessToken, array $config, array $payload): array
	{
		$path = sprintf(
			'v3/projects/%s/locations/%s:translateText',
			rawurlencode($config['projectId']),
			rawurlencode($config['location'])
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

			throw new rex_exception('Google Translate v3 request failed: ' . $message, $e);
		}

		$data = json_decode((string) $response->getBody(), true);
		if (!is_array($data)) {
			throw new rex_exception('Google Translate v3 returned an invalid JSON response.');
		}

		return $data;
	}

	private function buildModelName(array $config): string
	{
		$googleModel = trim((string) ($config['googleModel'] ?? ''));
		if ('' === $googleModel) {
			return '';
		}

		if (str_starts_with($googleModel, 'projects/')) {
			return $googleModel;
		}

		return sprintf(
			'projects/%s/locations/%s/models/%s',
			$config['projectId'],
			$config['location'],
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

	public function validateConfig(array $values): array
	{
		$errors = [];
		if (empty(trim((string) ($values['credentialsFile'] ?? '')))) {
			$errors['credentialsFile'] = 'Credentials file is required.';
		}
		return $errors;
	}
}