# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Provider errors are classified as `quota`, `auth`, `timeout` or `other` and stored with the HTTP status in the record's `data` column.
- Request option `throwOnError` to override the context-dependent error behaviour in both directions.
- `getLastResultMeta()` now reports `failed`, `error`, `errorType` and `httpStatus` for failed calls.

### Changed
- A failed provider call no longer produces a Whoops page in the frontend. `translate()` returns the untranslated source text, logs the exception to the REDAXO system log, and shows the message only to signed-in backend users. Backend and CLI keep throwing as before ([#7](https://github.com/FriendsOfREDAXO/vtrans/issues/7)).
- Updated Guzzle to 7.15.3 for the security fixes in 7.15.1 and 7.15.2.

### Removed
- Dead file `lib/Provider/VTransOpenAICompatibleProvider.php`, which PSR-4 never autoloaded because it declared `VTransOpenAIProvider`.

## [0.1.0-beta2] - 2026-06-26

### Changed
- Bundled the Composer dependencies in the addon vendor directory so the addon installer can run without a separate Composer step.
- Updated Guzzle-related dependencies to patched versions for current security fixes.

## [0.1.0-beta1] - 2026-06-18

### Added
- Initial beta release of vTrans for REDAXO 5.

### Changed
- Shifted configuration from static YAML-only lists to DB-backed backend connections with default/playground flags.
- Renamed the OpenAI provider identifier from `openai-compatible` to `openai` across the addon codebase, class names, and documentation.
- Improved provider handling for `context` and `customInstructions` where supported.
- Added richer data inspection and maintenance tools, including search, filters, batch delete, and inline editing.
- **Requires PHP >= 8.2** and **REDAXO >= 5.17.0**.
- Simplified addon bootstrapping to use Composer autoloading directly.
- Replaced verbose normalization helpers with concise `match` expressions.
- Used `readonly` value object for `VTransProviderResult`.
- Streamlined install script — removed obsolete index migration code.
- Cleaned up all provider classes with modern PHP idioms.

## [Unreleased]

### Changed

- Multi-provider translation service with support for DeepL (Free/Pro), Google Translate (Basic v2 and v3), LibreTranslate, and OpenAI APIs.
- New backend pages for managing connections, testing translations in the Playground, and reviewing stored translation data.
- HTML filtering for `translate="no"`, `.notranslate`, and `data-vtrans-exclude` blocks to protect content during translation.
- Stable key-based caching for reusable content, including retry support from stored records.
- No-cache mode via `cache => false` for direct provider calls without DB lookup or persistence.
- Raw provider metadata support for usage, rate limits, and debug information.
- Hash-based caching strategy to avoid duplicate API requests.
- Database-backed persistent storage for all translations with full metadata tracking.
- Backend testing page for manual translation with usage tracking and debug mode.
- Backend translation data management with search, filters, batch delete, and edit capabilities.
- YAML-based settings editor.
- Help pages with readme, changelog, and license integration.
- Support for context and custom instructions where providers allow it.
- Request-level `cacheMode = no-cache` for direct API translations without DB lookup and without persistence.
