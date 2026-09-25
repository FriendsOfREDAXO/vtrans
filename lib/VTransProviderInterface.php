<?php

namespace FriendsOfRedaxo\VTrans;

/**
 * Provider contract for all translation backends.
 */
interface VTransProviderInterface
{
	public function supports(string $api): bool;

	/**
	 * @param array<string, mixed> $modelData
	 * @param array<string, mixed> $requestOptions
	 */
	public function translate(string $text, ?string $srcLang, string $targetLang, string $format, array $modelData, array $requestOptions = []): VTransProviderResult;

	/**
	 * @param array<string, mixed> $modelData
	 * @return array<string, mixed>
	 */
	public function getUsage(array $modelData): array;

	/** @return array<string, string> Map of lang code => label (include 'auto' for auto-detect) */
	public function getAvailableSourceLanguages(): array;

	/** @return array<string, string> Map of lang code => label */
	public function getAvailableTargetLanguages(): array;

	/** Default target language code for this provider (must be English). */
	public function getDefaultTargetLanguage(): string;

	/** Human-readable provider label for the admin UI. */
	public function getProviderLabel(): string;

	/** API identifier(s) this provider supports. */
	/** @return list<string> */
	public function getApiIdentifiers(): array;

	/**
	 * Config field definitions for the connection form.
	 *
	 * Returns an array of field definitions. Each key is the field name.
	 * Fields with 'column' => true map to a dedicated DB column on rex_vtrans_connection,
	 * others are stored in the JSON params field. 'options' feeds a 'select' field;
	 * 'hidden' => true on 'timeout' hides the dedicated timeout input for providers
	 * that make no HTTP call of their own.
	 *
	 * @return array<string, array{type: string, label: string, required?: bool, column?: bool, default?: mixed, note?: string, options?: array<int|string, string>, hidden?: bool}>
	 */
	public function getConfigFields(): array;

	/**
	 * Validate connection configuration values for this provider.
	 *
	 * @param array<string, mixed> $values Field values to validate
	 * @return array<string, string> Map of field name => error message (empty if valid)
	 */
	public function validateConfig(array $values): array;

	/**
	 * Return raw HTTP debug data captured during the last translate() call.
	 *
	 * Always populated (request at minimum), even when the call fails.
	 * Empty array if no HTTP request was made (e.g. FakeLocal provider).
	 *
	 * @return array<string, mixed>
	 */
	public function getLastDebugData(): array;
}