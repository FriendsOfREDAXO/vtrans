<?php

namespace FriendsOfRedaxo\VTrans;

use FriendsOfRedaxo\VTrans\Provider\VTransAmazonTranslateProvider;
use FriendsOfRedaxo\VTrans\Provider\VTransDeepLProvider;
use FriendsOfRedaxo\VTrans\Provider\VTransFakeLocalProvider;
use FriendsOfRedaxo\VTrans\Provider\VTransGoogleTranslateBasicV2Provider;
use FriendsOfRedaxo\VTrans\Provider\VTransGoogleTranslateV3Provider;
use FriendsOfRedaxo\VTrans\Provider\VTransLibreTranslateProvider;
use FriendsOfRedaxo\VTrans\Provider\VTransMyMemoryProvider;
use FriendsOfRedaxo\VTrans\Provider\VTransOpenAIProvider;
use Throwable;
use rex;
use rex_addon;
use rex_exception;
use rex_sql;

/**
 * Central vTrans service.
 *
 * Responsibilities:
 * - resolve configured agents/providers
 * - normalize request data
 * - use cache and persist translation entries
 * - expose metadata of the last translation call
 */
class VTrans
{
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

	/**
	 * Global character limit (bytes) applied when no per-connection limit is set.
	 * Can be overridden per connection via the max_chars field.
	 */
	public const GLOBAL_MAX_CHARS = 10000;

	/**
	 * Global HTTP timeout (seconds) applied when no per-connection timeout is configured.
	 * Providers fall back to this value if their own default is not set.
	 */
	public const GLOBAL_TIMEOUT = 60;

	/** @var list<VTransProviderInterface>|null */
	private static ?array $providers = null;
	private static int $lastEntryId = 0;
	/** @var array<string, mixed> */
	private static array $lastResultMeta = [];
	/** @var array<string, mixed> */
	private static array $lastResultData = [];

	/**
	 * @param array<string, mixed> $requestOptions
	 */
	public static function translate(string $text = '', ?string $srcLang = null, string $targetLang = 'en', string $format = 'text', ?string $key = null, array $requestOptions = []): string|false
	{
		// Delegate HTML format to translateHtml() for automatic chunk handling.
		if ('html' === $format && empty($requestOptions['_skipChunking'])) {
			return self::translateHtml($text, $srcLang, $targetLang, $key, $requestOptions);
		}
		unset($requestOptions['_skipChunking']);

		// Reset request-local metadata before every new translation call.
		self::$lastEntryId = 0;
		self::$lastResultMeta = [];
		self::$lastResultData = [];

		$text = trim($text);

		if ('' === $text) {
			return false;
		}

		$connectionKey = self::normalizeStringValue($requestOptions['connection'] ?? $requestOptions['agent'] ?? $requestOptions['model'] ?? null);
		$connectionData = self::resolveConnection('' !== $connectionKey ? $connectionKey : null);
		$connectionConfig = self::normalizeArrayValue($connectionData['config'] ?? null);
		$connectionMaxCharsValue = self::normalizeStringValue($connectionConfig['maxChars'] ?? null);
		$connectionMaxChars = '' !== $connectionMaxCharsValue && is_numeric($connectionMaxCharsValue) && (int) $connectionMaxCharsValue > 0
			? (int) $connectionMaxCharsValue
			: self::GLOBAL_MAX_CHARS;
		$connectionDebugEnabled = self::normalizeDebugOption($connectionConfig['debug'] ?? false, false);
		$requestDebugProvided = array_key_exists('debug', $requestOptions);
		$debugEnabled = $requestDebugProvided
			? self::normalizeDebugOption($requestOptions['debug'], $connectionDebugEnabled)
			: $connectionDebugEnabled;
		$requestOptions['debug'] = $debugEnabled;
		$api = self::normalizeStringValue($connectionConfig['api'] ?? '');
		$supportsPromptOptions = self::apiSupportsPromptOptions($api);
		$entryKey = self::normalizeEntryKey($key);
		$srcLang = self::normalizeLanguage($srcLang);
		$targetLang = self::normalizeLanguage($targetLang);
		if (null === $targetLang) {
			$targetLang = self::normalizeLanguage(self::getDefaultTargetLanguage(self::normalizeStringValue($connectionData['key'] ?? null)));
		}
		$targetLang = self::normalizeTargetLanguage($targetLang, $api);
		$format = self::normalizeFormat($format);

		$requestContext = $supportsPromptOptions ? trim(self::normalizeStringValue($requestOptions['context'] ?? '')) : '';
		$customInstructions = $supportsPromptOptions ? self::normalizeCustomInstructions($requestOptions['customInstructions'] ?? []) : [];
		$requestOptions['customInstructions'] = $customInstructions;
		$requestOptions['context'] = $requestContext;
		$cacheEnabled = self::normalizeCacheEnabled($requestOptions);
		$requestOptions['cache'] = $cacheEnabled;
		$requestOptions['cacheMode'] = $cacheEnabled ? 'default' : 'no-cache';
		$contentLength = strlen($text);

		$promptContext = $supportsPromptOptions ? self::buildPromptContext($connectionConfig, $requestContext) : '';
		$hash = self::buildTranslationHash($text, $promptContext, $customInstructions);

		if (null === $targetLang) {
			throw new rex_exception('Target language is required.');
		}

		// HTML filter: pre-process to protect excluded/non-translatable content.
		$htmlFilter = null;
		$providerText = $text;
		if ('html' === $format) {
			$htmlFilter = new VTransHtmlFilter();
			$providerText = $htmlFilter->prepare($text);
		}

		if (!$cacheEnabled) {
			$payloadLength = mb_strlen($providerText);
			$pendingData = self::buildPendingData(
				$api, self::normalizeStringValue($connectionData['key'] ?? null), $srcLang, $targetLang, $format,
				$debugEnabled, self::normalizeIntValue($connectionConfig['timeout'] ?? null, self::GLOBAL_TIMEOUT),
				trim(self::normalizeStringValue($connectionConfig['apiUrl'] ?? '')),
				$promptContext, $customInstructions,
			);
			// When a key is provided, look up any existing row so storePendingEntry
			// can UPDATE it instead of INSERTing (avoids duplicate-key violation).
			$existingNoCacheEntry = null !== $entryKey
				? self::findEntryByKey($entryKey, self::normalizeStringValue($connectionData['key'] ?? null), $targetLang)
				: null;
			$existingNoCacheId = null !== $existingNoCacheEntry ? self::normalizeIntValue($existingNoCacheEntry['id'] ?? 0, 0) : null;
			$pendingId = self::storePendingEntry(
				$api, self::normalizeStringValue($connectionData['key'] ?? null), $hash, $text, $requestContext, $customInstructions,
				$srcLang, $targetLang, $format, $pendingData, $entryKey, $existingNoCacheId, $payloadLength
			);
			self::$lastEntryId = $pendingId;

			$mstart = microtime(true);
			$provider = self::getProvider($api);
			try {
				$requestOptions['promptContext'] = $promptContext;
				$result = $provider->translate($providerText, $srcLang, $targetLang, $format, $connectionData, $requestOptions);
				$durationMs = (int) round((microtime(true) - $mstart) * 1000);

				$translation = $result->getTranslation();
				if (null !== $htmlFilter) {
					$translation = $htmlFilter->restore($translation);
				}

				self::completeEntry($pendingId, $translation, $durationMs, $result->getData(), $payloadLength);

				self::setLastResultMeta(
					$pendingId,
					false,
					self::normalizeStringValue($connectionData['key'] ?? null),
					$api,
					$entryKey,
					[
						'hash' => $hash,
						'sourceLang' => $srcLang,
						'targetLang' => $targetLang,
						'format' => $format,
						'contentLength' => $contentLength,
						'promptOptionsUsed' => $supportsPromptOptions && ('' !== $requestContext || [] !== $customInstructions),
						'durationMs' => $durationMs,
						'cache' => false,
						'cacheMode' => 'no-cache',
					]
				);
				self::setLastResultData(self::normalizeResultData($result->getData()));

				return $translation;
			} catch (Throwable $e) {
				$durationMs = (int) round((microtime(true) - $mstart) * 1000);
				$errorData = [
					'status' => 'error',
					'error' => $e->getMessage(),
					'errorClass' => get_class($e),
				];
				$providerDebug = $provider->getLastDebugData();
				if ([] !== $providerDebug) {
					$errorData['_debug'] = $providerDebug;
				}
				self::updateEntryError($pendingId, $durationMs, $errorData);
				throw $e;
			}
		}

		$existingEntry = null;
		if (null !== $entryKey) {
			$existingEntry = self::findEntryByKey($entryKey, self::normalizeStringValue($connectionData['key'] ?? null), $targetLang);
			// Only treat as cache hit if the entry has a completed translation.
			$existingEntryHash = self::normalizeStringValue($existingEntry['hash'] ?? null);
			$existingEntryTranslation = $existingEntry['translation'] ?? null;
			if (null !== $existingEntry && $hash === $existingEntryHash && null !== $existingEntryTranslation && '' !== self::normalizeStringValue($existingEntryTranslation)) {
				self::setLastResultMeta(
					self::normalizeIntValue($existingEntry['id'] ?? 0, 0),
					true,
					self::normalizeStringValue($connectionData['key'] ?? null),
					$api,
					$entryKey,
					[
						'source' => 'key',
						'hash' => $hash,
						'sourceLang' => $srcLang,
						'targetLang' => $targetLang,
						'format' => $format,
						'contentLength' => $contentLength,
						'promptOptionsUsed' => $supportsPromptOptions && ('' !== $requestContext || [] !== $customInstructions),
						'durationMs' => self::normalizeIntValue($existingEntry['duration_ms'] ?? 0, 0),
					]
				);
				self::setLastResultData(self::normalizeResultData($existingEntry['data'] ?? []));

				return self::normalizeStringValue($existingEntry['translation'] ?? null);
			}
		}

		// Try cache first to avoid unnecessary API requests.
		$cachedTranslation = self::findCachedTranslation(
			$hash,
			$api,
			self::normalizeStringValue($connectionData['key'] ?? null),
			$srcLang,
			$targetLang,
			$format
		);
		if (null !== $cachedTranslation && null === $entryKey) {
			self::setLastResultMeta(
				self::normalizeIntValue($cachedTranslation['id'] ?? 0, 0),
				true,
				self::normalizeStringValue($connectionData['key'] ?? null),
				$api,
				null,
				[
					'hash' => $hash,
					'sourceLang' => $srcLang,
					'targetLang' => $targetLang,
					'format' => $format,
					'contentLength' => $contentLength,
					'promptOptionsUsed' => $supportsPromptOptions && ('' !== $requestContext || [] !== $customInstructions),
					'durationMs' => self::normalizeIntValue($cachedTranslation['duration_ms'] ?? 0, 0),
				]
			);
			self::setLastResultData(self::normalizeResultData($cachedTranslation['data'] ?? []));

			return self::normalizeStringValue($cachedTranslation['translation'] ?? null);
		}

		if (null !== $cachedTranslation && null !== $entryKey) {
			$insertId = self::storeTranslation(
				$api,
				self::normalizeStringValue($connectionData['key'] ?? null),
				$hash,
				$text,
				$requestContext,
				$customInstructions,
				$srcLang,
				$targetLang,
				$format,
				self::normalizeStringValue($cachedTranslation['translation'] ?? null),
				self::normalizeIntValue($cachedTranslation['duration_ms'] ?? 0, 0),
				self::normalizeResultData($cachedTranslation['data'] ?? []),
				$entryKey,
				null !== $existingEntry ? self::normalizeIntValue($existingEntry['id'] ?? 0, 0) : null,
				0 // cache hit — no payload sent to provider
			);

			self::setLastResultMeta(
				$insertId,
				true,
				self::normalizeStringValue($connectionData['key'] ?? null),
				$api,
				$entryKey,
				[
					'hash' => $hash,
					'sourceLang' => $srcLang,
					'targetLang' => $targetLang,
					'format' => $format,
					'contentLength' => $contentLength,
					'promptOptionsUsed' => $supportsPromptOptions && ('' !== $requestContext || [] !== $customInstructions),
					'durationMs' => self::normalizeIntValue($cachedTranslation['duration_ms'] ?? 0, 0),
					'cachedId' => self::normalizeIntValue($cachedTranslation['id'] ?? 0, 0),
				]
			);
			self::setLastResultData(self::normalizeResultData($cachedTranslation['data'] ?? []));

			return self::normalizeStringValue($cachedTranslation['translation'] ?? null);
		}

		// Create pending DB entry before sending to the provider.
		$payloadLength = mb_strlen($providerText);
		$pendingData = self::buildPendingData(
			$api, self::normalizeStringValue($connectionData['key'] ?? null), $srcLang, $targetLang, $format,
			$debugEnabled, self::normalizeIntValue($connectionConfig['timeout'] ?? null, self::GLOBAL_TIMEOUT),
			trim(self::normalizeStringValue($connectionConfig['apiUrl'] ?? '')),
			$promptContext, $customInstructions,
		);
		$pendingExistingId = null !== $existingEntry ? self::normalizeIntValue($existingEntry['id'] ?? 0, 0) : null;
		$pendingId = self::storePendingEntry(
			$api, self::normalizeStringValue($connectionData['key'] ?? null), $hash, $text, $requestContext, $customInstructions,
			$srcLang, $targetLang, $format, $pendingData, $entryKey, $pendingExistingId, $payloadLength
		);
		self::$lastEntryId = $pendingId;

		$mstart = microtime(true);
		$provider = self::getProvider($api);
		try {
			$requestOptions['promptContext'] = $promptContext;
			$result = $provider->translate($providerText, $srcLang, $targetLang, $format, $connectionData, $requestOptions);
			$durationMs = (int) round((microtime(true) - $mstart) * 1000);

			$translatedText = $result->getTranslation();
			if (null !== $htmlFilter) {
				$translatedText = $htmlFilter->restore($translatedText);
			}

			self::completeEntry($pendingId, $translatedText, $durationMs, $result->getData(), $payloadLength);

			self::setLastResultMeta(
				$pendingId,
				false,
				self::normalizeStringValue($connectionData['key'] ?? null),
				$api,
				$entryKey,
				[
					'hash' => $hash,
					'sourceLang' => $srcLang,
					'targetLang' => $targetLang,
					'format' => $format,
					'contentLength' => $contentLength,
					'promptOptionsUsed' => $supportsPromptOptions && ('' !== $requestContext || [] !== $customInstructions),
					'durationMs' => $durationMs,
				]
			);
			self::setLastResultData(self::normalizeResultData($result->getData()));

			return $translatedText;
		} catch (Throwable $e) {
			$durationMs = (int) round((microtime(true) - $mstart) * 1000);
			$errorData = [
				'status' => 'error',
				'error' => $e->getMessage(),
				'errorClass' => get_class($e),
			];
			$providerDebug = $provider->getLastDebugData();
			if ([] !== $providerDebug) {
				$errorData['_debug'] = $providerDebug;
			}
			self::updateEntryError($pendingId, $durationMs, $errorData);
			throw $e;
		}
	}

	/**
	 * Translate an HTML string, automatically extracting elements marked with
	 * `data-vtrans-key` so that each block is translated and cached under its own key.
	 *
	 * Top-level `data-vtrans-key` elements are extracted from the HTML before
	 * translation and replaced with inert placeholders. The surrounding HTML
	 * (the "shell") is then translated with `$shellKey`. Each extracted block
	 * is translated recursively using its `data-vtrans-key` value as the
	 * **complete** VTrans cache key — the language dimension is handled internally.
	 * All blocks are reinserted and the reassembled HTML is returned.
	 *
	 * Nesting is supported: a `data-vtrans-key` element may contain further
	 * `data-vtrans-key` descendants, each translated with their own key.
	 *
	 * If no `data-vtrans-key` elements are present this is equivalent to calling
	 * `translate()` with `format = 'html'`.
	 *
	 * @param string      $html           The full HTML to translate.
	 * @param string|null $srcLang        Source language code (e.g. 'de'), or null for auto-detect.
	 * @param string      $targetLang     Target language code (e.g. 'en').
	 * @param string|null $shellKey       VTrans cache key for the shell (surrounding HTML). Optional.
	 * @param array       $requestOptions Same options as {@see translate()}.
	 */
	/**
	 * @param array<string, mixed> $requestOptions
	 */
	public static function translateHtml(
		string $html,
		?string $srcLang,
		string $targetLang,
		?string $shellKey = null,
		array $requestOptions = [],
	): string|false {
		$html = trim($html);
		if ('' === $html) {
			return false;
		}

		$chunker   = new VTransHtmlChunker();
		$shellHtml = $chunker->extract($html);

		if (!$chunker->hasChunks()) {
			// No data-vtrans-key elements found — translate the whole HTML directly.
			return self::translate($html, $srcLang, $targetLang, 'html', $shellKey, array_merge($requestOptions, ['_skipChunking' => true]));
		}

		// Only call translate() for the shell when it contains actual content beyond
		// chunk placeholders — avoids unnecessary API calls for placeholder-only shells.
		$shellWithoutPlaceholders = preg_replace('/<vtrans-chunk\b[^>]*\/>/i', '', $shellHtml);
		if ('' !== trim((string) strip_tags((string) $shellWithoutPlaceholders))) {
			$translatedShell = self::translate($shellHtml, $srcLang, $targetLang, 'html', $shellKey, array_merge($requestOptions, ['_skipChunking' => true]));
			$translatedShell = false !== $translatedShell ? $translatedShell : $shellHtml;
		} else {
			$translatedShell = $shellHtml;
		}

		// Translate each extracted chunk recursively (handles nested data-vtrans-key).
		$translations = [];
		foreach ($chunker->getChunks() as $id => $chunk) {
			$translatedChunk   = self::translateHtml($chunk['html'], $srcLang, $targetLang, $chunk['key'], $requestOptions);
			$translations[$id] = false !== $translatedChunk ? $translatedChunk : $chunk['html'];
		}

		$result = $chunker->restore($translatedShell, $translations);
		return '' !== $result ? $result : false;
	}

	/** @return array<string, mixed> */
	public static function getLastResultMeta(): array
	{
		return self::$lastResultMeta;
	}

	/** @return array<string, mixed> */
	public static function getLastResultData(): array
	{
		return self::$lastResultData;
	}

	/**
	 * Return the DB id of the last entry created by translate().
	 * Available even when the call fails (pending/error entry).
	 */
	public static function getLastEntryId(): int
	{
		return self::$lastEntryId;
	}

	/** @param array<string, mixed> $data */
	private static function setLastResultData(array $data): void
	{
		self::$lastResultData = $data;
	}

	/** @return array<string, mixed> */
	private static function normalizeResultData(mixed $data): array
	{
		if (!is_array($data)) {
			return [];
		}

		$normalized = [];
		foreach ($data as $key => $value) {
			$normalized[(is_string($key) ? $key : (string) $key)] = self::normalizeResultValue($value);
		}

		return $normalized;
	}

	/** @return mixed */
	private static function normalizeResultValue(mixed $value): mixed
	{
		if (is_array($value)) {
			$normalized = [];
			foreach ($value as $key => $item) {
				$normalized[(is_string($key) ? $key : (string) $key)] = self::normalizeResultValue($item);
			}
			return $normalized;
		}

		if (is_object($value)) {
			return null;
		}

		return $value;
	}

	/** @param array<string, mixed> $extra */
	private static function setLastResultMeta(int $id, bool $cached, string $connectionKey, string $api, ?string $entryKey, array $extra = []): void
	{
		self::$lastResultMeta = array_merge([
			'id' => $id,
			'cached' => $cached,
			'cache' => true,
			'cacheMode' => 'default',
			'connection' => $connectionKey,
			'api' => $api,
			'key' => $entryKey,
			'hash' => null,
			'sourceLang' => null,
			'targetLang' => null,
			'format' => null,
			'contentLength' => 0,
			'promptOptionsUsed' => false,
			'durationMs' => 0,
		], $extra);
	}

	/** @return array<string, mixed>|null */
	private static function findEntryByKey(string $entryKey, string $connectionKey, string $targetLang): ?array
	{
		$sql = rex_sql::factory();
		$sql->setQuery(
			'SELECT id, hash, translation, duration_ms, data FROM ' . rex::getTable('vtrans') . ' WHERE `key` = ? AND `target` = ? AND `connection` = ? LIMIT 1',
			[$entryKey, $targetLang, $connectionKey]
		);

		if ($sql->getRows() <= 0) {
			return null;
		}

		return [
			'id' => (int) $sql->getValue('id'),
			'hash' => (string) $sql->getValue('hash'),
			'duration_ms' => (int) $sql->getValue('duration_ms'),
			'data' => self::decodeStoredData((string) $sql->getValue('data')),
			'translation' => $sql->getValue('translation'), // preserve NULL for pending/failed entries
		];
	}

	/** @return array<string, mixed> */
	public static function getUsage(?string $connectionKey = null): array
	{
		$connectionData = self::resolveConnection(self::trimOrNull($connectionKey));
		$connectionConfig = is_array($connectionData['config'] ?? null) ? $connectionData['config'] : [];
		$provider = self::getProvider(self::normalizeStringValue($connectionConfig['api'] ?? ''));

		return $provider->getUsage($connectionData);
	}

	public static function connectionSupportsPromptOptions(?string $connectionKey = null): bool
	{
		try {
			$connectionData = self::resolveConnection(self::trimOrNull($connectionKey));
		} catch (Throwable) {
			return true;
		}

		$connectionConfig = is_array($connectionData['config'] ?? null) ? $connectionData['config'] : [];
		return self::apiSupportsPromptOptions(self::normalizeStringValue($connectionConfig['api'] ?? ''));
	}

	/** @deprecated Use connectionSupportsPromptOptions() instead. */
	public static function modelSupportsPromptOptions(?string $model = null): bool
	{
		return self::connectionSupportsPromptOptions($model);
	}

	/** @return array<string, string> code => label; includes 'auto' entry if provider supports auto-detect */
	public static function getAvailableSourceLanguages(?string $connectionKey = null): array
	{
		try {
			$connectionData = self::resolveConnection(self::trimOrNull($connectionKey));
			$connectionConfig = is_array($connectionData['config'] ?? null) ? $connectionData['config'] : [];
			$provider = self::getProvider(self::normalizeStringValue($connectionConfig['api'] ?? ''));
			return $provider->getAvailableSourceLanguages();
		} catch (Throwable) {
			return ['auto' => 'Automatic'];
		}
	}

	/** @return array<string, string> code => label */
	public static function getAvailableTargetLanguages(?string $connectionKey = null): array
	{
		try {
			$connectionData = self::resolveConnection(self::trimOrNull($connectionKey));
			$connectionConfig = is_array($connectionData['config'] ?? null) ? $connectionData['config'] : [];
			$provider = self::getProvider(self::normalizeStringValue($connectionConfig['api'] ?? ''));
			return $provider->getAvailableTargetLanguages();
		} catch (Throwable) {
			return [];
		}
	}

	public static function getDefaultTargetLanguage(?string $connectionKey = null): string
	{
		try {
			$connectionData = self::resolveConnection(self::trimOrNull($connectionKey));
			$connectionConfig = is_array($connectionData['config'] ?? null) ? $connectionData['config'] : [];
			$provider = self::getProvider(self::normalizeStringValue($connectionConfig['api'] ?? ''));
			$defaultLang = strtolower(trim($provider->getDefaultTargetLanguage()));
			$available = $provider->getAvailableTargetLanguages();
			if ('' !== $defaultLang && ([] === $available || array_key_exists($defaultLang, $available))) {
				return $defaultLang;
			}
			if ([] !== $available) {
				return (string) array_key_first($available);
			}
		} catch (Throwable) {
		}

		return 'en';
	}

	/**
	 * Resolve the closest available target language for a provider given a requested code.
	 *
	 * Resolution order:
	 *  1. Exact match (case-insensitive key lookup).
	 *  2. Base-language prefix: requested='en' matches available 'en-gb', 'en-us' → first match.
	 *  3. Variant narrowing: requested='en-gb' matches available 'en' (base of requested).
	 *  4. Fallback: first available key, or the requested code as-is when the list is empty.
	 *
	 * @param string               $requested Requested language code (any case/separator).
	 * @param array<string, string> $available Provider's available target language map.
	 */
	public static function resolveClosestTargetLanguage(string $requested, array $available): string
	{
		if ([] === $available) {
			return strtolower(trim($requested));
		}

		$req = strtolower(str_replace('_', '-', trim($requested)));
		if ('' === $req) {
			return (string) array_key_first($available);
		}

		// 1. Exact match.
		if (array_key_exists($req, $available)) {
			return $req;
		}

		// Normalise available keys once.
		$normAvailable = array_combine(
			array_map(static fn(string $k): string => strtolower(str_replace('_', '-', $k)), array_keys($available)),
			array_keys($available)
		);

		if (array_key_exists($req, $normAvailable)) {
			return $normAvailable[$req];
		}

		$reqBase = explode('-', $req, 2)[0];

		// 2. Requested is a base code (e.g. 'en') – find first available variant (e.g. 'en-gb').
		foreach ($normAvailable as $normKey => $originalKey) {
			$availBase = explode('-', $normKey, 2)[0];
			if ($availBase === $req || $normKey === $req) {
				return $originalKey;
			}
		}

		// 3. Requested is a variant (e.g. 'en-gb') – find matching base in available (e.g. 'en').
		foreach ($normAvailable as $normKey => $originalKey) {
			if ($normKey === $reqBase) {
				return $originalKey;
			}
		}

		// 4. Fallback.
		return (string) array_key_first($available);
	}

	private static function trimOrNull(?string $value): ?string
	{
		if (null === $value) {
			return null;
		}

		$value = trim($value);
		return '' !== $value ? $value : null;
	}

	/**
	 * Resolve a connection by key from the database.
	 * If no key given, the default connection (lowest prio, active) is used.
	 *
	 * @return array<string, mixed>
	 */
	private static function resolveConnection(?string $connectionKey): array
	{
		if (null === $connectionKey || '' === $connectionKey) {
			$connection = VTransConnection::getDefault();
			if (null === $connection) {
				throw new rex_exception('No active translation connections configured for vTrans.');
			}
			return $connection->toModelData();
		}

		$connection = VTransConnection::getByKey($connectionKey);
		if (null === $connection) {
			throw new rex_exception('Translation connection not found: ' . $connectionKey);
		}

		return $connection->toModelData();
	}

	public static function getProvider(string $api): VTransProviderInterface
	{
		foreach (self::getProviders() as $provider) {
			if ($provider->supports($api)) {
				return $provider;
			}
		}

		throw new rex_exception('Unsupported translation API: ' . $api);
	}

	/** @return list<VTransProviderInterface> */
	public static function getProviders(): array
	{
		if (null === self::$providers) {
			self::$providers = [
				new VTransFakeLocalProvider(),
				new VTransDeepLProvider(),
				new VTransAmazonTranslateProvider(),
				new VTransGoogleTranslateBasicV2Provider(),
				new VTransGoogleTranslateV3Provider(),
				new VTransLibreTranslateProvider(),
				new VTransMyMemoryProvider(),
				new VTransOpenAIProvider(),
			];
		}

		return self::$providers;
	}

	/** Get a provider instance by its API identifier. */
	public static function getProviderByApi(string $api): ?VTransProviderInterface
	{
		foreach (self::getProviders() as $provider) {
			if ($provider->supports($api)) {
				return $provider;
			}
		}
		return null;
	}

	/** Get all available providers mapped by their first API identifier. */
	/** @return array<string, VTransProviderInterface> */
	public static function getAvailableProviders(): array
	{
		$map = [];
		foreach (self::getProviders() as $provider) {
			foreach ($provider->getApiIdentifiers() as $api) {
				$map[$api] = $provider;
			}
		}
		return $map;
	}

	/** @return array<string, mixed>|null */
	private static function findCachedTranslation(string $hash, string $api, string $connectionKey, ?string $srcLang, string $targetLang, string $format): ?array
	{
		$params = [$hash, $api, $connectionKey];
		$query = 'SELECT id, translation, duration_ms, data FROM ' . rex::getTable('vtrans') . ' WHERE hash = ? AND api = ? AND connection = ? AND source ';

		if (null !== $srcLang) {
			$query .= '= ?';
			$params[] = $srcLang;
		} else {
			$query .= 'IS NULL';
		}

		$query .= ' AND target = ? AND format = ? AND translation IS NOT NULL AND translation != ? ORDER BY id DESC LIMIT 1';
		$params[] = $targetLang;
		$params[] = $format;
		$params[] = '';

		$sql = rex_sql::factory();
		$sql->setQuery($query, $params);

		if ($sql->getRows() <= 0) {
			return null;
		}

		return [
			'id' => (int) $sql->getValue('id'),
			'duration_ms' => (int) $sql->getValue('duration_ms'),
			'data' => self::decodeStoredData((string) $sql->getValue('data')),
			'translation' => (string) $sql->getValue('translation'),
		];
	}

	/**
	 * @param list<string> $customInstructions
	 * @param array<string, mixed> $data
	 */
	private static function storeTranslation(string $api, string $connectionKey, string $hash, string $text, string $prompt, array $customInstructions, ?string $srcLang, string $targetLang, string $format, string $translation, int $durationMs, array $data, ?string $entryKey = null, ?int $existingId = null, int $payloadLength = 0): int
	{
		$table = rex::getTable('vtrans');
		$customInstructionsJson = [] !== $customInstructions
			? json_encode($customInstructions, self::JSON_FLAGS)
			: null;
		$dataJson = json_encode($data, self::JSON_FLAGS);

		if (null !== $existingId && $existingId > 0) {
			rex_sql::factory()->setQuery(
				'UPDATE ' . $table . ' SET api = ?, connection = ?, hash = ?, length = ?, payload_length = ?, source = ?, target = ?, format = ?, text = ?, prompt = ?, custom_instructions = ?, translation = ?, duration_ms = ?, data = ?, updateuser = ?, updatedate = NOW() WHERE id = ?',
				[
					$api,
					$connectionKey,
					$hash,
					strlen($text),
					$payloadLength,
					$srcLang,
					$targetLang,
					$format,
					$text,
					$prompt,
					$customInstructionsJson,
					$translation,
					$durationMs,
					$dataJson,
					self::getCurrentUserLogin(),
					$existingId,
				]
			);

			return $existingId;
		}

		$sql = rex_sql::factory();
		$sql->setTable($table);
		$sql->setValue('api', $api);
		$sql->setValue('connection', $connectionKey);
		$sql->setValue('key', $entryKey);
		$sql->setValue('hash', $hash);
		$sql->setValue('length', strlen($text));
		$sql->setValue('payload_length', $payloadLength);
		$sql->setValue('source', $srcLang);
		$sql->setValue('target', $targetLang);
		$sql->setValue('format', $format);
		$sql->setValue('text', $text);
		$sql->setValue('prompt', $prompt);
		$sql->setValue('custom_instructions', $customInstructionsJson);
		$sql->setValue('translation', $translation);
		$sql->setValue('duration_ms', $durationMs);
		$sql->setValue('data', $dataJson);
		$sql->setValue('createuser', self::getCurrentUserLogin());
		$sql->insert();

		return (int) $sql->getLastId();
	}

	/** @return array<string, mixed> */
	private static function decodeStoredData(string $data): array
	{
		$data = trim($data);
		if ('' === $data) {
			return [];
		}

		$decodedData = json_decode($data, true);
		return self::normalizeArrayValue($decodedData);
	}

	/** @return array<string, mixed> */
	private static function normalizeArrayValue(mixed $value): array
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

	private static function normalizeStringValue(mixed $value): string
	{
		return is_string($value) ? $value : '';
	}

	private static function normalizeIntValue(mixed $value, int $default): int
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

	private static function normalizeEntryKey(?string $entryKey): ?string
	{
		if (null === $entryKey) {
			return null;
		}

		$entryKey = trim($entryKey);
		if ('' === $entryKey) {
			return null;
		}

		if (strlen($entryKey) > 191) {
			throw new rex_exception('key is too long. Maximum length is 191 characters.');
		}

		if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $entryKey)) {
			throw new rex_exception('key contains invalid characters. Allowed: letters, numbers, underscore, dot, colon, dash.');
		}

		return $entryKey;
	}

	private static function normalizeLanguage(?string $lang): ?string
	{
		if (null === $lang) {
			return null;
		}

		$lang = trim($lang);
		if ('' === $lang || 'auto' === strtolower($lang)) {
			return null;
		}

		return strtoupper($lang);
	}

	private static function normalizeFormat(string $format): string
	{
		return match (strtolower(trim($format))) {
			'html' => 'html',
			default => 'text',
		};
	}

	/**
	 * @param mixed $instructions
	 * @return list<string>
	 */
	private static function normalizeCustomInstructions(mixed $instructions): array
	{
		if (is_string($instructions)) {
			$instructions = preg_split('/\r\n|\r|\n/', $instructions) ?: [];
		}

		$normalized = [];
		foreach ((array) $instructions as $instruction) {
			$instruction = trim(self::normalizeStringValue($instruction));
			if ('' !== $instruction) {
				$normalized[] = $instruction;
			}
		}

		return array_slice(array_values(array_unique($normalized)), 0, 10);
	}

	private static function normalizeDebugOption(mixed $value, bool $default = false): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (null === $value) {
			return $default;
		}

		if (is_int($value) || is_float($value)) {
			return 0 !== (int) $value;
		}

		return match (strtolower(trim(self::normalizeStringValue($value)))) {
			'1', 'true', 'yes', 'on' => true,
			'0', 'false', 'no', 'off' => false,
			default => $default,
		};
	}

	/** @param array<string, mixed> $requestOptions */
	private static function normalizeCacheEnabled(array $requestOptions): bool
	{
		if (array_key_exists('cache', $requestOptions)) {
			return self::normalizeBoolOption($requestOptions['cache'], true);
		}

		return 'no-cache' !== self::normalizeCacheMode($requestOptions['cacheMode'] ?? 'default');
	}

	private static function normalizeBoolOption(mixed $value, bool $default = false): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (null === $value) {
			return $default;
		}

		if (is_int($value) || is_float($value)) {
			return 0 !== (int) $value;
		}

		return match (strtolower(trim(self::normalizeStringValue($value)))) {
			'1', 'true', 'yes', 'on' => true,
			'0', 'false', 'no', 'off' => false,
			default => $default,
		};
	}

	private static function normalizeCacheMode(mixed $value): string
	{
		$normalized = strtolower(trim(self::normalizeStringValue($value)));

		return match ($normalized) {
			'no-cache', 'nocache', 'none', 'off' => 'no-cache',
			default => 'default',
		};
	}

	private static function normalizeTargetLanguage(?string $targetLang, string $api): ?string
	{
		if (null === $targetLang) {
			return null;
		}

		if (!in_array($api, ['deepl-v2', 'deepl-api-free-v2', 'deepl-api-pro-v2'], true)) {
			return $targetLang;
		}

		return match ($targetLang) {
			'EN' => 'EN-GB',
			'PT' => 'PT-PT',
			default => $targetLang,
		};
	}

	private static function apiSupportsPromptOptions(string $api): bool
	{
		return in_array($api, ['deepl-v2', 'deepl-api-free-v2', 'deepl-api-pro-v2', 'openai'], true);
	}

	/** @param array<string, mixed> $config */
	private static function buildPromptContext(array $config, string $prompt): string
	{
		$parts = [];

		$systemPrompt = trim(self::normalizeStringValue($config['systemPrompt'] ?? ''));
		if ('' !== $systemPrompt) {
			$parts[] = $systemPrompt;
		}

		$prompt = trim($prompt);
		if ('' !== $prompt) {
			$parts[] = $prompt;
		}

		return implode("\n\n", $parts);
	}

	/** @param list<string> $customInstructions */
	private static function buildTranslationHash(string $text, string $promptContext, array $customInstructions): string
	{
		return md5($text . "\n" . $promptContext . "\n" . implode('|', $customInstructions));
	}

	/**
	 * Build the data payload for a pending DB entry (before the API call).
	 *
	 * Stores all available context so that incomplete/error entries already
	 * contain the same metadata that a successful debug-mode response would.
	 */
	/**
	 * @param list<string> $customInstructions
	 * @return array<string, mixed>
	 */
	private static function buildPendingData(
		string $api,
		string $connectionKey,
		?string $srcLang,
		string $targetLang,
		string $format,
		bool $debugEnabled,
		int $timeout,
		string $apiUrl,
		string $promptContext,
		array $customInstructions,
	): array {
		$data = [
			'status' => 'pending',
			'requestedAt' => date('c'),
			'model' => $connectionKey,
			'api' => $api,
			'apiUrl' => '' !== $apiUrl ? $apiUrl : null,
			'sourceLang' => $srcLang,
			'targetLang' => $targetLang,
			'format' => $format,
			'debug' => $debugEnabled,
			'timeout' => $timeout,
		];
		if ('' !== $promptContext) {
			$data['promptContext'] = $promptContext;
		}
		if ([] !== $customInstructions) {
			$data['customInstructions'] = $customInstructions;
		}
		return $data;
	}

	/**
	 * Insert or update a pending entry before the provider API call.
	 * Translation and duration_ms are set to NULL.
	 */
	/**
	 * @param list<string> $customInstructions
	 * @param array<string, mixed> $pendingData
	 */
	private static function storePendingEntry(
		string $api,
		string $connectionKey,
		string $hash,
		string $text,
		string $prompt,
		array $customInstructions,
		?string $srcLang,
		string $targetLang,
		string $format,
		array $pendingData,
		?string $entryKey = null,
		?int $existingId = null,
		int $payloadLength = 0,
	): int {
		$table = rex::getTable('vtrans');
		$customInstructionsJson = [] !== $customInstructions
			? json_encode($customInstructions, self::JSON_FLAGS)
			: null;
		$dataJson = json_encode($pendingData, self::JSON_FLAGS);

		if (null !== $existingId && $existingId > 0) {
			rex_sql::factory()->setQuery(
				'UPDATE ' . $table . ' SET api = ?, connection = ?, hash = ?, length = ?, payload_length = ?, source = ?, target = ?, format = ?, text = ?, prompt = ?, custom_instructions = ?, translation = NULL, duration_ms = NULL, data = ?, updateuser = ?, updatedate = NOW() WHERE id = ?',
				[
					$api,
					$connectionKey,
					$hash,
					strlen($text),
					$payloadLength,
					$srcLang,
					$targetLang,
					$format,
					$text,
					$prompt,
					$customInstructionsJson,
					$dataJson,
					self::getCurrentUserLogin(),
					$existingId,
				]
			);
			return $existingId;
		}

		$sql = rex_sql::factory();
		$sql->setTable($table);
		$sql->setValue('api', $api);
		$sql->setValue('connection', $connectionKey);
		$sql->setValue('key', $entryKey);
		$sql->setValue('hash', $hash);
		$sql->setValue('length', strlen($text));
		$sql->setValue('payload_length', $payloadLength);
		$sql->setValue('source', $srcLang);
		$sql->setValue('target', $targetLang);
		$sql->setValue('format', $format);
		$sql->setValue('text', $text);
		$sql->setValue('prompt', $prompt);
		$sql->setValue('custom_instructions', $customInstructionsJson);
		$sql->setValue('translation', null);
		$sql->setValue('duration_ms', null);
		$sql->setValue('data', $dataJson);
		$sql->setValue('createuser', self::getCurrentUserLogin());
		$sql->insert();

		return (int) $sql->getLastId();
	}

	/**
	 * Update a pending entry after a successful provider response.
	 */
	/** @param array<string, mixed> $data */
	private static function completeEntry(int $entryId, string $translation, int $durationMs, array $data, int $payloadLength = 0): void
	{
		$table = rex::getTable('vtrans');
		$dataJson = json_encode($data, self::JSON_FLAGS);

		rex_sql::factory()->setQuery(
			'UPDATE ' . $table . ' SET translation = ?, duration_ms = ?, data = ?, payload_length = ?, updateuser = ?, updatedate = NOW() WHERE id = ?',
			[
				$translation,
				$durationMs,
				$dataJson,
				$payloadLength,
				self::getCurrentUserLogin(),
				$entryId,
			]
		);
	}

	/**
	 * Update a pending entry after a provider error or timeout.
	 *
	 * Reads the existing data column and merges the error information into it
	 * so that all pre-request context (stored by storePendingEntry) is preserved.
	 */
	/** @param array<string, mixed> $errorInfo */
	private static function updateEntryError(int $entryId, int $durationMs, array $errorInfo): void
	{
		$table = rex::getTable('vtrans');

		$existing = rex_sql::factory()->getArray(
			'SELECT data FROM ' . $table . ' WHERE id = ? LIMIT 1',
			[$entryId]
		);

		$existingData = [];
		if (!empty($existing[0]['data'])) {
			$decoded = json_decode((string) $existing[0]['data'], true);
			if (is_array($decoded)) {
				$existingData = $decoded;
			}
		}

		$mergedData = array_merge($existingData, $errorInfo, [
			'durationMs' => $durationMs,
			'errorAt' => date('c'),
		]);

		$dataJson = json_encode($mergedData, self::JSON_FLAGS);

		rex_sql::factory()->setQuery(
			'UPDATE ' . $table . ' SET duration_ms = ?, data = ?, updateuser = ?, updatedate = NOW() WHERE id = ?',
			[
				$durationMs,
				$dataJson,
				self::getCurrentUserLogin(),
				$entryId,
			]
		);
	}

	private static function getCurrentUserLogin(): string
	{
		return (string) (rex::getUser()?->getLogin() ?? 'system');
	}
}

