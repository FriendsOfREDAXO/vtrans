# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The `1.0.0-beta` line is the release candidate for the first production release `1.0.0`.
Breaking changes may still occur until `1.0.0` is tagged.

## [Unreleased]

## [1.0.0-beta5] - 2026-09-25

HTML translations now keep Bootstrap and other JavaScript components working and translate image descriptions and other attribute texts. Prompted by a Bootstrap carousel whose controls stopped working and whose `alt` texts stayed German on translated pages.

### Added
- Attribute values are translated in HTML mode: `alt`, `title`, `placeholder`, `aria-label`, `aria-description`, `aria-roledescription`, `aria-placeholder`, and `value` on `<input type="button|submit|reset">`. `VTransHtmlFilter` moves the values into a block appended to the same request and writes the translations back afterwards, so it works with every provider and needs no additional API calls. Values without letters, URLs, paths, file names and values containing markup are skipped, as is everything marked `translate="no"`, `.notranslate` or `data-vtrans-exclude`. Translated values are reduced to plain text and escaped before they go back into their attribute.
- Per-connection settings "Attribute übersetzen" (on by default, also for existing connections) and an optional attribute list replacing the defaults (new columns `translate_attributes`, `translate_attribute_list`).

### Changed
- The sanitiser keeps all `data-*` and `aria-*` attributes. They used to be stripped, which broke Bootstrap components (`data-bs-toggle`, `data-bs-target`, `data-bs-slide`, …) and removed accessibility labels. `on*` attributes, `javascript:`/`data:` URLs and script-capable elements are still removed.
- Records whose source contains translatable attributes or `data-*`/`aria-*` attributes get a new cache hash and are re-translated once on their next request; keyed records are updated in place. All other records keep their hash.
- The HTML rule of the LLM system prompt names the `<vtrans-attr>` carrier and the attribute tokens.
- The attribute string markers `notranslate` and `data-vtrans-exclude` now match whole names only; `class="notranslate-hint"` no longer excludes an element.
- The connection form hides "Zusätzlich erlauben" (`sanitize_allow_extra`) while HTML sanitisation is off, since it has no effect then; the stored value is kept. Contributed by [@TobiasKrais](https://github.com/TobiasKrais) ([#14](https://github.com/FriendsOfREDAXO/vtrans/pull/14)).
- README formatting: blank lines around headings and lists, linked support URLs — in both the English and the German README. Contributed by [@TobiasKrais](https://github.com/TobiasKrais) ([#15](https://github.com/FriendsOfREDAXO/vtrans/pull/15)).

### Fixed
- HTML sanitisation truncated every translation longer than 20,000 bytes: Symfony's HTML sanitizer cuts its input at that length by default. The limit is lifted; `max_chars` remains the place to bound request sizes.
- A chunk shell whose only translatable content is attribute values (e.g. images with `alt` texts) is now translated instead of skipped.
- The key field on the connection form validates in the browser again. Its pattern `[a-z0-9_-]+` is invalid under the `v` flag that current browsers use for the `pattern` attribute, so they ignored it and accepted any input until the server rejected it on save.

## [1.0.0-beta4] - 2026-09-21

vTrans can now translate through the [ai_platform](https://github.com/FriendsOfREDAXO/ai_platform) addon. The provider was contributed by [@TobiasKrais](https://github.com/TobiasKrais) — many thanks! ([#13](https://github.com/FriendsOfREDAXO/vtrans/pull/13), closes [#12](https://github.com/FriendsOfREDAXO/vtrans/issues/12)). Building on it, `openai` and `ai_platform` now share one way of building the system prompt, with placeholders for the languages.

### Added
- New translation provider `ai_platform`. Instead of calling an LLM endpoint itself, a connection points at a text profile of the ai_platform addon; provider, model, API key, temperature and token limits all live in that profile. Without the addon the provider stays inert (empty profile select, clear error on use), so vTrans keeps no hard dependency on it.
- Placeholders in the system prompt of `openai` and `ai_platform` connections: `{source_lang}`, `{target_lang}` (codes such as `DE`, `EN-GB`) and `{source_lang_name}`, `{target_lang_name}` (names from the provider's language list). The connection form shows the default prompt as placeholder text, explains the placeholders, and warns on save when a custom prompt names no target language.
- The connection form gained a `select` field type, used by the profile picker. Picking a profile prefills key and label from the profile name while those fields are still empty.
- A provider can mark its `timeout` config field as `hidden`; the form then drops the timeout input and keeps the stored value. `ai_platform` uses this, since the HTTP call is made by the ai_platform addon.

### Changed
- `openai` and `ai_platform` build their system prompt through the shared `VTransPrompt`, so a connection's system prompt behaves the same for both: it replaces the default prompt entirely. For `ai_platform`, the prompt comes from the vTrans connection, not from the ai_platform profile — vTrans cannot see the profile's prompt, so a change there would not refresh cached translations, whereas the connection prompt is part of the cache hash.
- The format rule is appended even when a custom system prompt is set. Before, a custom `openai` prompt dropped it, so an HTML request could lose the `<vtrans-ph>` placeholders of excluded regions. The HTML rule now names those placeholders explicitly.
- The default prompt names languages ("German", "English – British") instead of codes. Cached translations are not affected.

### Fixed
- `openai` sent a literal `\n` instead of a line break before the `Context:` and `Additional instructions:` blocks.

## [1.0.0-beta3] - 2026-09-07

### Fixed
- Regions marked as "do not translate" keep their event handlers again. `VTransHtmlFilter` masked `translate="no"` and `.notranslate` elements by replacing only their *inner* content, so the opening tag stayed in the stream that is handed to the sanitiser — a `<button class="notranslate" onclick="…">` came back without its `onclick`. The whole element is masked now, opening tag included, exactly as `data-vtrans-exclude` and `<script>`/`<style>` already were. Nothing an author marked as excluded passes through the sanitiser any more; everything else still does.

### Added
- Sanitisation is configurable per connection. `sanitize_html` (default `1`) switches it off for everything written for that connection — the provider's answer and manual edits on the data page alike; `sanitize_allow_extra` widens the allowlist by named attributes (`onclick`) and elements (`<iframe>`) instead of dropping the filter entirely. Both are edited on the connection page; the list shows the state per connection. Existing installations get the columns with the safe default, so nothing changes for them until an admin changes it.
- `tests/sanitizer.php`, a standalone script (no REDAXO, no database) covering the filter/sanitiser round trip: excluded regions keep their handlers, ordinary translated text does not, a disabled connection stores raw HTML, and an untouched connection stays on the safe default.

### Changed
- Hardened the first restore pass of `VTransHtmlFilter` against an answer that pairs the placeholders itself and then loses a closing tag: the pattern no longer runs past a following opening placeholder to reach a distant `</vtrans-ph>`, which truncated everything in between. With sanitisation active this cannot occur — the sanitiser's parser balances the answer beforehand — so this only matters for connections with `sanitize_html = 0`, and the behaviour on the sanitised path is unchanged.
- Schema: `rex_vtrans_connection` gains `sanitize_html` (`tinyint(1)`, default `1`) and `sanitize_allow_extra` (`text`, nullable). Added idempotently in `install.php`, so a reinstall and an installer update both apply them.

## [1.0.0-beta2] - 2026-09-07

### Fixed
- Nested placeholders in the HTML filter are now fully resolved. `VTransHtmlFilter::prepare()` masks `script`/`style`/`code`/`svg` before it masks `data-vtrans-exclude` and `translate="no"`/`.notranslate` elements, so a stored fragment could itself contain placeholders. `restore()` ran a single pass and left those inner placeholders as literal `<vtrans-ph id="N"/>` text — an excluded block containing an SVG icon, a `<style>` or a `<script>` lost that content in the output. `restore()` now repeats until nothing changes, bounded by the number of placeholders.

### Changed
- Internal cleanups with no effect on behaviour: the placeholder callback in `VTransHtmlFilter` moved into a typed method, and a condition in `VTrans::translate()` that could never evaluate to `false` was removed — a keyless cache hit returns one branch earlier, so the additional key check was already implied. The addon is clean under rexstan at level 10.

## [1.0.0-beta] - 2026-08-22

Release candidate for the first production version. It repairs the update path, makes
`max_chars` and the keyed-record identity work as documented, widens the storage columns
and fixes two display-level defects.

**This release is the baseline for the schema.** Database changes from here on will ship
with a migration; earlier records are not migrated.

### Added
- `max_chars` is finally applied. The value was computed and never read, so the documented limit did not exist. It is now advisory: exceeding it writes a line to the REDAXO system log (once per connection and request) and the request still goes out, because rejecting would break every site whose articles are simply longer than the configured value.

### Fixed
- A keyed record is now identified by key, **source language**, target language, connection and **format**. Previously the lookup ignored source and format, so the same key handed back HTML markup in a text context or a translation made from a different source language. The unique index was widened accordingly.
- **Schema changes now reach existing installations.** REDAXO runs `update.php` — not `install.php` — when an already installed addon is updated through the installer, and `update.php` was empty. Every schema change since the first release therefore only ever applied to fresh installs or to a manual reinstall. `update.php` now runs the idempotent schema definition from `install.php`.
- Search on the Data page escaped `%` and `_` in the wrong order, which doubled the escape character it had just inserted and left the wildcard active — searching for a literal `%` returned wrong rows. No injection was possible; values were still quoted.
- The status of a failed record is escaped before output.

### Changed
- `text` and `translation` are now `MEDIUMTEXT` instead of `TEXT`. `TEXT` holds 64 KB, so a longer HTML article was truncated on write and the stored `hash` no longer matched the stored `text`, leaving the cache entry permanently inconsistent.
- `installer_ignore` excludes internal notes and editor files, so they cannot end up in an installer package built from a working copy.

### Removed
- Dead assignment in the `vtrans_agent` → `vtrans_connection` migration.

## [0.9.1-beta] - 2026-08-19

### Added
- Dependency `symfony/html-sanitizer` (pinned to the 7.x line, which supports PHP 8.2; 8.x requires PHP 8.4). `config.platform.php` is set to `8.2.0` so Composer resolves against the addon's declared minimum instead of the developer's local PHP version.
- Provider errors are classified as `quota`, `auth`, `timeout` or `other` and stored with the HTTP status in the record's `data` column.
- Request option `throwOnError` to override the context-dependent error behaviour in both directions.
- `getLastResultMeta()` now reports `failed`, `error`, `errorType` and `httpStatus` for failed calls.

### Security
- Untrusted HTML is sanitised before it is stored. Both the provider's answer and a translation edited on the Data page (open to `vtrans[]`, a permission that does not imply the right to publish HTML) are rendered as HTML in the frontend on every cache hit. Scripting, event attributes, frames, forms and `javascript:` URLs are removed via `symfony/html-sanitizer`; links, images, classes and inline styles are kept. The author's own `<script>`/`<style>` blocks are restored after sanitisation and stay untouched.
- CSRF protection on every state-changing backend action. Deleting a record, the batch delete, saving an edited translation, and creating, editing, deleting, reordering or toggling a connection now all require a valid `rex_csrf_token`. Without it, a forged request could have repointed a connection's `api_url` at a foreign host, or wiped the whole translation table — the batch delete builds its `WHERE` from the filter parameters and is unbounded when no filter is set.
- The batch delete additionally verifies the number of affected rows against the count shown on the button and aborts on mismatch.
- Provider error messages are redacted before they are stored, logged or displayed. Guzzle embeds the full request URI in its exception messages, and Google Translate Basic v2 and MyMemory pass the API key as a query parameter — the key therefore reached the `data` column, which is readable on the Data page by every user holding `vtrans[]`, while the API keys themselves live on the admin-only Connections page.

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

### Feature set of the initial release

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
