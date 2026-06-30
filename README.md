# vTrans BETA for REDAXO 5

vTrans bundles several text-processing APIs behind a single interface,
stores the results in the database, and provides backend pages for testing,
analysis, and maintenance.

The primary use case is translation. With LLM-based providers (for example the OpenAI provider),
it can also be used for other scenarios where the source and target language are the same,
for example summarizing, rephrasing, or editing content.

> Note: vTrans is currently in an early beta phase. Production use is only recommended after appropriate testing
> and close monitoring.

---

## Installation

Install it via the REDAXO installer (not yet available) or copy it manually to `redaxo/src/addons/vtrans` and activate it in the backend.

**Requirements:**
- REDAXO >= 5.17.0
- PHP >= 8.2

---

## Quick Start

1. In the backend, open `vTrans -> Connections` and create a connection.
2. Select a provider (for example `deepl-api-free-v2`, `openai`, or `google-translate-basic-v2`).
3. Enter the API key, API URL, and any additional parameters.
4. Optionally mark it as the default and/or enable it for the Playground.
5. Open `vTrans -> Playground` and test it.

Example for a DeepL Free connection:
- Key: `deepl_free`
- Label: `DeepL Free`
- Provider: `deepl-api-free-v2`
- API URL: `https://api-free.deepl.com/v2/translate`
- API Key: `YOUR_DEEPL_KEY`

Notes:
- Free keys belong to the free API URL `https://api-free.deepl.com/v2/translate`.
- The default connection is used automatically when no `connection` value is passed in the request.

---

## Features

- Access several providers through one unified API
- Manage connections centrally in the backend
- Test requests manually in the Playground
- DB cache is monitored via string hash, connection, language, and format
- Stable keys (optional) for reusable content
- Search, filter, review, and edit stored records under `Data`
- Keep provider metadata such as usage or rate limits as raw data

---

## Supported Providers / APIs

### DeepL
- Market leader with very good quality for common languages
- `deepl-api-free-v2`
- `deepl-api-pro-v2`
- Supports `context` and `customInstructions`

### Amazon Translate
- Good to very good translation quality
- `amazon-translate-v1`
- API-key-/credential-based depending on the provider implementation

### Google Translate Basic v2
- Good to very good translation quality
- `google-translate-basic-v2`
- API-key-based
- No prompt options

### Google Translate v3
- Very good translation quality
- Service-account / OAuth-based
- No prompt options

### LibreTranslate
- Good quality - sufficient for most use cases
- Open source - can also be self-hosted
- `libretranslate-v1`
- Optional `apiKey`
- No prompt options

### MyMemory
- Simple, rather technical translation
- `mymemory-v2`
- Endpoint-based (default: `https://api.mymemory.translated.net/get`)
- Optional `apiKey` and `email`
- No prompt options

### OpenAI-compatible LLMs
- Flexible depending on the model
- `openai`
- Freely configurable endpoints and parameters
- Supports `context` and `customInstructions`

### Fake Local
- Useful during development
- Generates simple test output to verify the functionality
- Local only - no API - no costs

---

## Costs
The costs of the different providers vary widely and usually consist of a monthly base fee (subscription) and costs per 1 million characters. There are also often free or included quotas. This is something everyone needs to compare for themselves. LibreTranslate can also be self-hosted on suitable hardware. For a large website, you should expect around 20–50 EUR per language (of course only a rough estimate).

## Configuration

Configuration is done via the backend page `Connections`. There, connections are defined. Depending on the interface, the corresponding details are stored:

- Key (identification)
- Label (name)
- Provider / API (provider / interface)
- Debug flag
- Timeout
- Max. characters
- Playground flag (available in the Playground)
- Various provider-specific parameters

Notes:
- The default connection is used automatically when no individual `Connection` is defined in the request.
- The default connection and availability in the Playground can be switched quickly in the Connections overview.

---

## Playground

Requests can be tested manually here.

### Inputs
- Connection
- Source and target language
- Format (`text` or `html`)
- Text
- Optional key
- Additional `context` and `customInstructions` where supported

### Key behavior

If a `key` is set, vTrans works with a stable dataset per model and target language.

- If an entry with the same hash already exists, it is reused.
- If the content has changed, the existing key-based record is updated.
- Without a key, only the normal cache based on hash, connection, language, and format is used.

## Usage in Code

vTrans uses the namespace `FriendsOfRedaxo\VTrans`.

```php
use FriendsOfRedaxo\VTrans\VTrans;           // Namespace for the VTrans class

$translated = VTrans::translate(
    '<p>Hello world</p>',                    // content to translate
    'en',                                    // source language (also 'auto' possible)
    'de',                                    // target language
    'html',                                  // format (text or html)
    'homepage_hero',                         // optional key
    [                                        // additional optional parameters
        'connection' => 'deepl_free',        // connection (otherwise the default connection is used)
        'context' => 'Marketing headline',   // additional context (if supported)
        'customInstructions' => [            // additional instructions (if supported)
            'Use formal tone.',
            'Keep HTML tags unchanged.',
        ],
    ]
);

echo $translated;

// Optional debug output
$meta = VTrans::getLastResultMeta();
$data = VTrans::getLastResultData();
dump($meta);
dump($data);
```

Supported request options include:

- `connection`: key of a saved connection (recommended)
- `context`: additional context for supported providers
- `customInstructions`: array or multiline string with additional instructions
- `debug`: enables provider debug data
- `cache`: boolean (`true` by default). With `false`, DB cache lookup and persistence are skipped.

## Simple Template Example

This example simply translates the German original content and outputs it in another language when no content is available for that language — meaning the article is empty.

```php
<?php
use FriendsOfRedaxo\VTrans\VTrans;

// Current language of the REDAXO article context
$curLang = rex_clang::getCurrent()->getCode();

// Content of the current article
$articleContent = $this->getArticle();

// If we are in a non-default language and there is no content yet,
// retrieve the German original content and let vTrans translate it.
if (rex_clang::getCurrentId() !== 1 && $articleContent === '') {
    // German original content from the base language (ID 1)
    $articleContentOrg = (new rex_article_content(rex_article::getCurrentId(), 1))->getArticle();

    $articleContent = VTrans::translate(
        $articleContentOrg,                         // Content to translate
        'de',                                       // Source language
        $curLang,                                   // Target language
        'html',                                     // Format
        'artCont-' . rex_article::getCurrentId()    // Key for better caching
    );
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($curLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8">
    <title>vTrans Demo</title>
</head>
<body>
    <h1>vTrans Demo</h1>
    <!-- Simple language switcher for the demo -->
    <nav>
        <?php foreach (rex_clang::getAll(true) as $lang): ?>
            <?php if ($lang->getValue('id') === rex_clang::getCurrentId()): ?>
                | <strong><?php echo htmlspecialchars($lang->getValue('name')); ?></strong>
            <?php else: ?>
                | <a href="<?php echo rex_getUrl($this->getValue('article_id'), $lang->getValue('id')); ?>">
                    <?php echo htmlspecialchars($lang->getValue('name')); ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php echo $articleContent; ?>
</body>
</html>
```

In this example, only the actual content is translated. Other parts such as navigation, footer, meta data, etc. can be translated in the same way. For navigation, manual translation is often recommended, because this often also affects the URL.

### No-Cache Mode

With `cache => false`, a request can be executed directly via the API without first checking the database and without saving the result afterwards.

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
- no result is stored in `rex_vtrans`
- raw provider data is still available directly via `VTrans::getLastResultData()`

`VTrans::getLastResultData()` returns the raw data from the last request, such as usage data, rate limits, debug information, or provider-specific metadata.

---

## HTML Filter (Exclude Content from Processing)

When using the `html` format, a provider-independent HTML filter automatically protects certain content before the API call and restores it after translation.

### Do not translate (`translate="no"`, `.notranslate`)

```html
<span translate="no">Thomas König</span>
<span class="notranslate">REDAXO CMS</span>
```

### Exclude entire blocks (`data-vtrans-exclude`)

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

## Support

- Project: https://github.com/FriendsOfREDAXO/vtrans
- Community: https://www.redaxo.org

## Credits

- Friends Of REDAXO
- [Matthias Weiss / VIEWSION.net](https://github.com/VIEWSION) (Lead)

---

## License

MIT, see `LICENSE`.
