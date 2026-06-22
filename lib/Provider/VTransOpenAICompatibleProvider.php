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
 * Generic OpenAI chat-completions provider.
 */
class VTransOpenAIProvider implements VTransProviderInterface
{
	/** @var array<string, mixed> */
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'openai' === $api;
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
			'timeout' => $config['timeout'],
			'handler' => $stack,
		]);

		$customInstructions = $this->mergeCustomInstructions(
			$this->normalizeInstructions($config['customInstructions'] ?? []),
			$this->normalizeInstructions($requestOptions['customInstructions'] ?? [])
		);

		$messages = $this->buildMessages(
			$this->normalizeString($text),
			$srcLang,
			$targetLang,
			$format,
			$this->normalizeString($config['systemPrompt'] ?? null),
			$this->normalizeString($requestOptions['promptContext'] ?? null),
			$customInstructions
		);

		$payload = [
			'model' => $config['model'],
			'messages' => $messages,
		];

		if (null !== $config['temperature']) {
			$payload['temperature'] = $config['temperature'];
		}

		if (null !== $config['maxTokens']) {
			$payload['max_tokens'] = $config['maxTokens'];
		}

		try {
			$responsePayload = $this->sendRequest($client, $config, $payload);
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
		$data = is_array($responsePayload['data'] ?? null) ? $responsePayload['data'] : [];
		/** @var array<string, mixed> $data */
		$translation = $this->extractTranslation($data);

		if ('' === $translation) {
			throw new rex_exception('OpenAI API returned an empty translation response.');
		}

		$resultData = [
			'model' => $modelData['key'],
			'api' => $config['api'],
			'apiUrl' => $config['apiUrl'],
			'provider_model' => $config['model'],
			'format' => $format,
			'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : null,
			'rate_limit' => is_array($responsePayload['rate_limit'] ?? null) ? $responsePayload['rate_limit'] : null,
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
			'provider' => 'openai',
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

	/**
	 * @param array<string, mixed> $modelConfig
	 * @return array<string, mixed>
	 */
	private function normalizeConfig(array $modelConfig): array
	{
		$api = trim($this->normalizeString($modelConfig['api'] ?? null));
		$apiUrl = trim($this->normalizeString($modelConfig['apiUrl'] ?? 'https://api.openai.com/v1/chat/completions'));
		$apiKey = trim($this->normalizeString($modelConfig['apiKey'] ?? null));
		$model = trim($this->normalizeString($modelConfig['model'] ?? 'gpt-4o-mini'));
		$timeout = $this->normalizeInt($modelConfig['timeout'] ?? null, 90);
		$timeout = max(1, min($timeout, 300));

		$temperatureRaw = $modelConfig['temperature'] ?? null;
		$temperature = null;
		if (null !== $temperatureRaw && '' !== trim($this->normalizeString($temperatureRaw))) {
			$temperature = (float) $this->normalizeString($temperatureRaw);
			$temperature = max(0.0, min($temperature, 2.0));
		}

		$maxTokensRaw = $modelConfig['maxTokens'] ?? null;
		$maxTokens = null;
		if (null !== $maxTokensRaw && '' !== trim($this->normalizeString($maxTokensRaw))) {
			$maxTokensInt = $this->normalizeInt($maxTokensRaw, 0);
			if ($maxTokensInt > 0) {
				$maxTokens = $maxTokensInt;
			}
		}

		$systemPrompt = trim($this->normalizeString($modelConfig['systemPrompt'] ?? null));
		$customInstructions = $this->normalizeInstructions($modelConfig['customInstructions'] ?? []);

		if ('' === $apiKey) {
			throw new rex_exception('OpenAI provider requires an apiKey in the model configuration.');
		}

		if ('' === $apiUrl) {
			throw new rex_exception('OpenAI provider requires an apiUrl in the model configuration.');
		}

		if ('' === $model) {
			throw new rex_exception('OpenAI provider requires a model in the model configuration.');
		}

		return [
			'api' => $api,
			'apiUrl' => $apiUrl,
			'apiKey' => $apiKey,
			'model' => $model,
			'temperature' => $temperature,
			'maxTokens' => $maxTokens,
			'systemPrompt' => $systemPrompt,
			'customInstructions' => $customInstructions,
			'timeout' => $timeout,
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
	 * @param mixed $instructions
	 * @return list<string>
	 */
	private function normalizeInstructions(mixed $instructions): array
	{
		if (is_string($instructions)) {
			$instructions = preg_split('/\r\n|\r|\n/', $instructions) ?: [];
		}

		$normalized = [];
		foreach ((array) $instructions as $instruction) {
			$instruction = trim($this->normalizeString($instruction));
			if ('' !== $instruction) {
				$normalized[] = $instruction;
			}
		}

		return $normalized;
	}

	/**
	 * @param mixed $configInstructions
	 * @param mixed $requestInstructions
	 * @return list<string>
	 */
	private function mergeCustomInstructions(mixed $configInstructions, mixed $requestInstructions): array
	{
		$merged = array_merge($this->normalizeCustomInstructions($configInstructions), $this->normalizeCustomInstructions($requestInstructions));
		$merged = array_values(array_unique($merged, SORT_STRING));

		return $merged;
	}

	/**
	 * @param mixed $instructions
	 * @return list<string>
	 */
	private function normalizeCustomInstructions(mixed $instructions): array
	{
		if (is_string($instructions)) {
			$instructions = preg_split('/\r\n|\r|\n/', $instructions) ?: [];
		}

		$normalized = [];
		foreach ((array) $instructions as $instruction) {
			$instruction = trim($this->normalizeString($instruction));
			if ('' !== $instruction) {
				$normalized[] = $instruction;
			}
		}

		return $normalized;
	}

	/**
	 * @param string|list<string>|array<string, mixed> $customInstructions
	 * @return list<array<string, mixed>>
	 */
	private function buildMessages(string $text, ?string $srcLang, string $targetLang, string $format, string $systemPrompt, string $promptContext, string|array $customInstructions): array
	{
		$source = null !== $srcLang && '' !== trim($srcLang) ? trim($srcLang) : 'auto-detect';
		$formatInstruction = match ($format) {
			'html' => 'Input is HTML. Preserve HTML tags, attributes and structure. Translate only user-visible text.',
			default => 'Input is plain text. Return plain text only.',
		};

		$instructionLines = $this->normalizeCustomInstructions($customInstructions);

		$systemPrompt = trim($systemPrompt);
		$systemParts = [];
		if ('' !== $systemPrompt) {
			// Configured systemPrompt replaces the built-in default system prompt entirely.
			$systemParts[] = $systemPrompt;
		} else {
			$systemParts = [
				'You are a professional translation engine.',
				'Translate from ' . $source . ' to ' . $targetLang . '.',
				$formatInstruction,
				'Return only the translated text, without explanations, quotes, markdown fences, or extra comments.',
			];
		}

		$promptContext = trim($promptContext);
		if ('' !== $systemPrompt && '' !== $promptContext) {
			if ($promptContext === $systemPrompt) {
				$promptContext = '';
			} elseif (str_starts_with($promptContext, $systemPrompt . "\n\n")) {
				$promptContext = trim(substr($promptContext, strlen($systemPrompt . "\n\n")));
			}
		}

		if ('' !== $promptContext) {
			$systemParts[] = 'Context:\n' . $promptContext;
		}

		if ([] !== $instructionLines) {
			$systemParts[] = 'Additional instructions:\n- ' . implode("\n- ", $instructionLines);
		}

		return [
			[
				'role' => 'system',
				'content' => implode("\n\n", $systemParts),
			],
			[
				'role' => 'user',
				'content' => $text,
			],
		];
	}

	/**
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function sendRequest(Client $client, array $config, array $payload): array
	{
		try {
			$response = $client->post($this->normalizeString($config['apiUrl'] ?? null), [
				'headers' => [
					'Accept' => 'application/json',
					'Authorization' => 'Bearer ' . $this->normalizeString($config['apiKey'] ?? null),
				],
				'json' => $payload,
			]);
		} catch (ConnectException $e) {
			throw new rex_exception('OpenAI request timed out or connection failed.', $e);
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
					$errorPayload = [];
					if (is_array($decoded)) {
						$errorPayload = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
					}
					$apiMessage = $this->normalizeString($errorPayload['message'] ?? null);
					if ('' !== $apiMessage) {
						$message = $apiMessage;
					}
				}
				$message .= ' (HTTP ' . $status . ')';
			}

			throw new rex_exception('OpenAI request failed: ' . $message, new \RuntimeException($e->getMessage(), 0, $e));
		}

		$data = $this->normalizeJsonArray(json_decode((string) $response->getBody(), true));
		if ([] === $data) {
			throw new rex_exception('OpenAI provider returned an invalid JSON response.');
		}

		$rateLimit = $this->extractRateLimit($response);

		return [
			'data' => $data,
			'rate_limit' => $rateLimit,
		];
	}

	/** @return array<string, string>|null */
	private function extractRateLimit(\Psr\Http\Message\ResponseInterface $response): ?array
	{
		$rateLimit = array_filter([
			'requests_limit' => $response->getHeaderLine('x-ratelimit-limit-requests'),
			'requests_remaining' => $response->getHeaderLine('x-ratelimit-remaining-requests'),
			'requests_reset' => $response->getHeaderLine('x-ratelimit-reset-requests'),
			'tokens_limit' => $response->getHeaderLine('x-ratelimit-limit-tokens'),
			'tokens_remaining' => $response->getHeaderLine('x-ratelimit-remaining-tokens'),
			'tokens_reset' => $response->getHeaderLine('x-ratelimit-reset-tokens'),
		], static fn(string $v): bool => '' !== $v);

		return [] !== $rateLimit ? $rateLimit : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function extractTranslation(array $data): string
	{
		$choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
		$firstChoice = [] !== $choices && isset($choices[0]) && is_array($choices[0]) ? $choices[0] : [];
		$message = is_array($firstChoice['message'] ?? null) ? $firstChoice['message'] : [];
		$content = $message['content'] ?? null;

		if (is_string($content)) {
			return trim($content);
		}

		if (is_array($content)) {
			$text = implode('', array_filter(array_map(
				static fn(mixed $part): string => is_array($part) && isset($part['text']) && is_string($part['text']) ? trim($part['text']) : '',
				$content,
			)));
			if ('' !== $text) {
				return trim($text);
			}
		}

		return trim($this->normalizeString($data['output_text'] ?? null));
	}

	/** @return array<string, mixed> */
	public function getLastDebugData(): array
	{
		return $this->lastDebugData;
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

	/**
	 * @param array<string, mixed> $transaction
	 * @return array<string, mixed>
	 */
	private function buildDebugData(array $transaction): array
	{
		$req = $transaction['request'] ?? null;
		$res = $transaction['response'] ?? null;

		$requestHeaders = [];
		if ($req instanceof \Psr\Http\Message\RequestInterface) {
			$requestHeaders = $req->getHeaders();
			if (isset($requestHeaders['Authorization'])) {
				$requestHeaders['Authorization'] = ['Bearer ***'];
			}
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
				'headers' => $requestHeaders,
				'body' => $this->normalizeJsonArray(json_decode((string) $req->getBody(), true)),
			] : null,
			'response' => $responseData,
		];
	}

	public function getProviderLabel(): string
	{
		return 'OpenAI';
	}

	/** @return list<string> */
	public function getApiIdentifiers(): array
	{
		return ['openai'];
	}

	public function getConfigFields(): array
	{
		return [
			'api_key' => ['type' => 'text', 'label' => 'API Key', 'required' => true, 'column' => true],
			'api_url' => ['type' => 'text', 'label' => 'API URL', 'required' => false, 'column' => true, 'default' => 'https://api.openai.com/v1/chat/completions'],
			'system_prompt' => ['type' => 'textarea', 'label' => 'System Prompt', 'required' => false, 'column' => true],
			'timeout' => ['type' => 'number', 'label' => 'Timeout (s)', 'required' => false, 'column' => true, 'default' => 90],
			'model' => ['type' => 'text', 'label' => 'Model', 'required' => false, 'default' => 'gpt-4o-mini', 'note' => 'z. B. gpt-4o-mini, gpt-4o, claude-3-haiku, etc.'],
			'temperature' => ['type' => 'text', 'label' => 'Temperature', 'required' => false, 'default' => '0.2', 'note' => '0.0 – 2.0'],
			'maxTokens' => ['type' => 'text', 'label' => 'Max Tokens', 'required' => false, 'default' => ''],
			'customInstructions' => ['type' => 'textarea', 'label' => 'Custom Instructions', 'required' => false, 'note' => 'One instruction per line (max 10).'],
		];
	}

	/** @param array<string, mixed> $values @return array<string, string> */
	public function validateConfig(array $values): array
	{
		$errors = [];
		if (empty(trim($this->normalizeString($values['api_key'] ?? null)))) {
			$errors['api_key'] = 'API Key is required.';
		}
		return $errors;
	}
}
