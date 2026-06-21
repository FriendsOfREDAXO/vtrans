# vTrans BETA for REDAXO 5

vTrans combines several text-processing APIs behind a single interface,
stores results in the database, and adds backend pages for testing,
analysis, and maintenance.

The main use case is translation. With LLM-based providers (for example the OpenAI provider),
it can also be used for cases where the source and target language are the same,
for example summarising, rephrasing, or content editing.

> Note: vTrans is currently in an early beta stage. Production use is only recommended after careful review
> and close monitoring.

---

## Installation

Install it via the REDAXO installer, or copy it manually to `redaxo/src/addons/vtrans` and activate it in the backend.

**Requirements:**
- REDAXO >= 5.17.0
- PHP >= 8.2

---

## Quick Start

1. In the backend, open `vTrans -> Connections` and create a connection.
2. Select a provider such as `deepl-api-free-v2`, `openai`, or `google-translate-basic-v2`.
3. Enter the API key / API URL and any additional provider-specific settings.
4. Optionally mark it as the default and/or enable it for the playground.
5. Open `vTrans -> Playground` and test it.

Example for a DeepL Free connection:
- Key: `deepl_free`
- Label: `DeepL Free`
- Provider: `deepl-api-free-v2`
- API URL: `https://api-free.deepl.com/v2/translate`
- API Key: `YOUR_DEEPL_KEY`

Notes:
- Free keys use the free translation endpoint `https://api-free.deepl.com/v2/translate`.
- The default connector is used automatically whenever no `connection` value is passed.

---

## Features

- Access multiple providers through one unified API
- Manage connections centrally in the backend
- Test requests manually in the Playground
- Use DB caching by hash, connection, language, and format
- Support stable keys for reusable content
- Search, filter, review, and maintain stored records under `Data`
- Keep provider metadata such as usage or rate limits as raw data

---

## Supported Providers / APIs

### DeepL
- `deepl-api-free-v2`
- `deepl-api-pro-v2`
- Supports `context` and `customInstructions`

### Amazon Translate
- `amazon-translate-v1`
- Credential/API-key based, depending on the provider implementation

### Google Translate Basic v2
- `google-translate-basic-v2`
- API-key based
- No prompt-style options

### Google Translate v3
- `google-translate-v3`
- Service-account / OAuth based
- No prompt-style options

### LibreTranslate
- `libretranslate-v1`
- Optional `apiKey`
- No prompt-style options

### MyMemory
- `mymemory-v2`
- Endpoint based (default: `https://api.mymemory.translated.net/get`)
- Optional `apiKey` and `email`
- No prompt-style options

### OpenAI
- `openai`
- Freely configurable endpoints and parameters
- Supports `context` and `customInstructions`

---

## Configuration

Configuration now takes place on the backend page `Connections`. There, connections are managed with:

- Key
- Label
- Provider
- API key / API URL
- System prompt
- Timeout
- Maximum characters
- Debug
- Playground flag
- Provider-specific parameters

Important notes:
- The default connector is used automatically when no `connection` value is provided.
- API usage loads connections from the database and passes them into `VTrans::translate()`.
- Multiple provider configurations can exist side by side for new integrations.

---

## Playground

This is where requests can be tested manually.

### Inputs
- Connection
- Source and target language
- Format (`text` or `html`)
- Text
- Optional key
- Additional `context` and `customInstructions` where supported

### Key behavior

When a `key` is set, vTrans works with stable, reusable records per connection and target language.

- If an entry with the same hash already exists, it is reused.
- If the content changes, the existing key record is updated.
- Without a key, only the normal hash-based cache is used.

### Result block

The result panel shows, among other things:
- connection and record ID
- whether the result came from cache or the API
- token and rate-limit data, if available
- a link to the details view under `Data`

`VTrans::getLastResultMeta()` also provides request-local metadata such as:
- `id`
- `cached`
- `cacheMode`
- `connection`
- `api`
- `key`
- `hash`
- `sourceLang`
- `targetLang`
- `format`
- `contentLength`
- `promptOptionsUsed`
- `durationMs`

---

## Data

Records are stored in `rex_vtrans`.

Important fields include:
- `api`
- `connection`
- `key`
- `hash`
- `source`, `target`, `format`
- `text`, `translation`
- `length`, `duration_ms`
- `prompt`, `custom_instructions`
- `data`
- `createdate`, `createuser`, `updatedate`, `updateuser`

Cache and reuse are based on:
- request hash
- connection / provider
- source / target language
- format

Key-based records are bound to `key + target + connection`.

---

## Usage in Code

vTrans uses the namespace `FriendsOfRedaxo\\VTrans`.

```php
use FriendsOfRedaxo\\VTrans\\VTrans;

$translated = VTrans::translate(
	'<p>Hello world</p>',
	'en',
	'de',
	'html',
	'homepage_hero',
	[
		'connection' => 'deepl_free',
		'context' => 'Marketing headline',
		'customInstructions' => [
			'Use a formal tone.',
			'Keep HTML tags unchanged.',
		],
	]
);

$meta = VTrans::getLastResultMeta();
$data = VTrans::getLastResultData();
```

Supported request options include:

- `connection`: key of a stored connection (recommended)
- `context`: additional context for supported providers
- `customInstructions`: array or multiline string with extra guidance
- `debug`: enables provider debug data in the raw result
- `cache`: boolean (`true` by default). Set to `false` to skip DB cache lookup and persistence.

### No-cache mode

Use `cache => false` for direct API calls that should not look up or store anything in the database.

```php
$translated = VTrans::translate(
	$text,
	'de',
	'en',
	'text',
	null,
	[
		'connection' => 'openai_default',
		'cache' => false,
	]
);

$meta = VTrans::getLastResultMeta();
$data = VTrans::getLastResultData();
```

In no-cache mode:

- no database lookup is performed before the provider call
- no result record is saved afterwards
- raw provider data is still available directly via `VTrans::getLastResultData()`

`VTrans::getLastResultData()` returns the raw data from the last request, for example usage values, rate limits, debug information, or provider-specific metadata.

---

## HTML Filter (Exclude Content from Processing)

When using the `html` format, a provider-independent HTML filter automatically protects certain content before the API call and restores it after translation.

### Do not translate (`translate=\"no\"`, `.notranslate`)

```html
<span translate=\"no\">Thomas König</span>
<span class=\"notranslate\">REDAXO CMS</span>
```

### Exclude whole blocks (`data-vtrans-exclude`)

```html
<div data-vtrans-exclude>
	<script>var config = { lang: 'de' };</script>
	<p>This block is not sent to the API.</p>
</div>
```

### Automatically excluded tags

- `<script>…</script>`
- `<style>…</style>`
- `<code>…</code>`
- `<svg>…</svg>`

---

## Troubleshooting

- “No active translation connections configured for vTrans.”: No active connection exists in the backend.
- “Translation connection not found”: The passed `connection` key does not exist.
- “Unsupported translation API”: The provider name of a connection is unknown.
- No usage display: Only supported by providers that return such endpoints.

---

## Support

- Project: https://github.com/FriendsOfREDAXO/vtrans
- Community: https://www.redaxo.org

## Credits

- Friends Of REDAXO
- [Matthias Weiss / VIEWSION.net](https://github.com/VIEWSION) (Lead)

---

## License

MIT, see `LICENSE`.

