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
	private array $lastDebugData = [];

	public function supports(string $api): bool
	{
		return 'openai' === $api;
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

		$customInstructions = $this->mergeCustomInstructions(
			$config['customInstructions'] ?? [],
			$requestOptions['customInstructions'] ?? []
		);

		$messages = $this->buildMessages(
			(string) $text,
			$srcLang,
			$targetLang,
			$format,
			(string) ($config['systemPrompt'] ?? ''),
			(string) ($requestOptions['promptContext'] ?? ''),
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
				$this->lastDebugData = $this->buildDebugData($history[0]);
			}
		}
		$data = $responsePayload['data'];
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

	public function getUsage(array $modelData): array
	{
		$config = $this->normalizeConfig($modelData['config']);

		return [
			'provider' => 'openai',
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

	private function normalizeConfig(array $modelConfig): array
	{
		$api = trim((string) ($modelConfig['api'] ?? ''));
		$apiUrl = trim((string) ($modelConfig['apiUrl'] ?? 'https://api.openai.com/v1/chat/completions'));
		$apiKey = trim((string) ($modelConfig['apiKey'] ?? ''));
		$model = trim((string) ($modelConfig['model'] ?? 'gpt-4o-mini'));
		$timeout = (int) ($modelConfig['timeout'] ?? 90);
		$timeout = max(1, min($timeout, 300));

		$temperatureRaw = $modelConfig['temperature'] ?? null;
		$temperature = null;
		if (null !== $temperatureRaw && '' !== trim((string) $temperatureRaw)) {
			$temperature = (float) $temperatureRaw;
			$temperature = max(0.0, min($temperature, 2.0));
		}

		$maxTokensRaw = $modelConfig['maxTokens'] ?? null;
		$maxTokens = null;
		if (null !== $maxTokensRaw && '' !== trim((string) $maxTokensRaw)) {
			$maxTokensInt = (int) $maxTokensRaw;
			if ($maxTokensInt > 0) {
				$maxTokens = $maxTokensInt;
			}
		}

		$systemPrompt = trim((string) ($modelConfig['systemPrompt'] ?? ''));
		$customInstructions = $modelConfig['customInstructions'] ?? [];

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

	private function mergeCustomInstructions(string|array $configInstructions, string|array $requestInstructions): array
	{
		$merged = array_merge($this->normalizeCustomInstructions($configInstructions), $this->normalizeCustomInstructions($requestInstructions));
		$merged = array_values(array_unique($merged, SORT_STRING));

		return $merged;
	}

	private function normalizeCustomInstructions(string|array $instructions): array
	{
		if (is_string($instructions)) {
			$instructions = preg_split('/\r\n|\r|\n/', $instructions) ?: [];
		}

		$normalized = [];
		foreach ((array) $instructions as $instruction) {
			$instruction = trim((string) $instruction);
			if ('' !== $instruction) {
				$normalized[] = $instruction;
			}
		}

		return $normalized;
	}

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

	private function sendRequest(Client $client, array $config, array $payload): array
	{
		try {
			$response = $client->post($config['apiUrl'], [
				'headers' => [
					'Accept' => 'application/json',
					'Authorization' => 'Bearer ' . $config['apiKey'],
				],
				'json' => $payload,
			]);
		} catch (ConnectException $e) {
			throw new rex_exception('OpenAI request timed out or connection failed.', $e);
		} catch (GuzzleException $e) {
			$message = $e->getMessage();
			if (method_exists($e, 'getResponse') && null !== $e->getResponse()) {
				$status = $e->getResponse()->getStatusCode();
				$body = (string) $e->getResponse()->getBody();
				if ('' !== $body) {
					$decoded = json_decode($body, true);
					$apiMessage = (string) ($decoded['error']['message'] ?? '');
					if ('' !== $apiMessage) {
						$message = $apiMessage;
					}
				}
				$message .= ' (HTTP ' . $status . ')';
			}

			throw new rex_exception('OpenAI request failed: ' . $message, $e);
		}

		$data = json_decode((string) $response->getBody(), true);
		if (!is_array($data)) {
			throw new rex_exception('OpenAI provider returned an invalid JSON response.');
		}

		$rateLimit = $this->extractRateLimit($response);

		return [
			'data' => $data,
			'rate_limit' => $rateLimit,
		];
	}

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

	private function extractTranslation(array $data): string
	{
		$content = $data['choices'][0]['message']['content'] ?? null;

		if (is_string($content)) {
			return trim($content);
		}

		if (is_array($content)) {
			$text = implode('', array_filter(array_map(
				static fn(array $part): string => (string) ($part['text'] ?? ''),
				$content,
			)));
			if ('' !== $text) {
				return trim($text);
			}
		}

		return trim((string) ($data['output_text'] ?? ''));
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
		return 'OpenAI';
	}

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

	public function validateConfig(array $values): array
	{
		$errors = [];
		if (empty(trim((string) ($values['api_key'] ?? '')))) {
			$errors['api_key'] = 'API Key is required.';
		}
		return $errors;
	}
}
