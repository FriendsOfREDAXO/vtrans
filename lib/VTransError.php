<?php

namespace FriendsOfRedaxo\VTrans;

use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Classifies provider failures.
 *
 * Providers throw plain rex_exceptions, so a quota problem is not
 * distinguishable from a wrong API key without looking closer. This helper
 * walks the exception chain for the original HTTP response and falls back to
 * reading the status out of the message text, because not every provider
 * keeps the Guzzle exception in the chain.
 */
final class VTransError
{
	/** Limit/quota reached — retrying later may help, retrying now will not. */
	public const TYPE_QUOTA = 'quota';

	/** Credentials rejected — needs a config change in the backend. */
	public const TYPE_AUTH = 'auth';

	/** Timeout or connection failure. */
	public const TYPE_TIMEOUT = 'timeout';

	/** Anything else (invalid JSON, empty response, unsupported language, …). */
	public const TYPE_OTHER = 'other';

	/**
	 * Status codes that always mean "limit reached".
	 * 456 is DeepL's non-standard "Quota Exceeded".
	 */
	private const QUOTA_STATUS = [402, 429, 456];

	private const AUTH_STATUS = [401, 403];

	/**
	 * Message fragments that identify a quota problem when the status code is
	 * missing or ambiguous — Google answers 403 both for a spent quota and for
	 * a bad key, OpenAI reports `insufficient_quota` with 429.
	 */
	private const QUOTA_HINTS = [
		'insufficient_quota',
		'quota',
		'rate limit',
		'ratelimitexceeded',
		'too many requests',
		'exceeded your current',
		'billing',
		'credit balance',
		// Google answers a spent contingent with `403 Daily Limit Exceeded`,
		// Amazon throttles with ThrottlingException. The space in 'limit exceeded'
		// matters: it keeps Amazon's TextSizeLimitExceededException — a payload
		// problem, not a quota one — out of this bucket.
		'limit exceeded',
		'daily limit',
		'throttling',
	];

	private const TIMEOUT_HINTS = [
		'timed out',
		'timeout',
		'connection failed',
		'could not resolve',
		'connection refused',
	];

	/**
	 * @return array{type: string, status: int|null}
	 */
	public static function classify(Throwable $e): array
	{
		$status = self::extractStatus($e);
		$message = strtolower(self::collectMessages($e));

		if (null !== $status && in_array($status, self::QUOTA_STATUS, true)) {
			return ['type' => self::TYPE_QUOTA, 'status' => $status];
		}

		if (self::containsAny($message, self::QUOTA_HINTS)) {
			return ['type' => self::TYPE_QUOTA, 'status' => $status];
		}

		if (null !== $status && in_array($status, self::AUTH_STATUS, true)) {
			return ['type' => self::TYPE_AUTH, 'status' => $status];
		}

		if (self::containsAny($message, self::TIMEOUT_HINTS)) {
			return ['type' => self::TYPE_TIMEOUT, 'status' => $status];
		}

		return ['type' => self::TYPE_OTHER, 'status' => $status];
	}

	/**
	 * Read the HTTP status of the failed request.
	 *
	 * Walks the exception chain first (Guzzle exceptions carry the response),
	 * then falls back to the status the providers append to their messages.
	 */
	public static function extractStatus(Throwable $e): ?int
	{
		$current = $e;
		while (null !== $current) {
			if (method_exists($current, 'getResponse')) {
				$response = $current->getResponse();
				if ($response instanceof ResponseInterface) {
					return $response->getStatusCode();
				}
			}
			$current = $current->getPrevious();
		}

		return self::extractStatusFromMessage(self::collectMessages($e));
	}

	private static function extractStatusFromMessage(string $message): ?int
	{
		// `(HTTP 429)` — appended by OpenAI, Amazon, LibreTranslate, Google Basic.
		// `(status 429)` — MyMemory.
		// "resulted in a `456 Quota Exceeded` response" — Guzzle's own wording, the
		// only place DeepL's status survives.
		$patterns = [
			'/\(HTTP (\d{3})\)/i',
			'/\(status (\d{3})\)/i',
			'/resulted in a [`\'"]?(\d{3})/i',
		];

		foreach ($patterns as $pattern) {
			if (1 === preg_match($pattern, $message, $matches)) {
				return (int) $matches[1];
			}
		}

		return null;
	}

	/** Concatenate the messages of the whole exception chain. */
	private static function collectMessages(Throwable $e): string
	{
		$messages = [];
		$current = $e;
		while (null !== $current) {
			$messages[] = $current->getMessage();
			$current = $current->getPrevious();
		}

		return implode(' ', $messages);
	}

	/** @param list<string> $needles */
	private static function containsAny(string $haystack, array $needles): bool
	{
		foreach ($needles as $needle) {
			if (str_contains($haystack, $needle)) {
				return true;
			}
		}

		return false;
	}
}
