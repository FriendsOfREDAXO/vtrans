<?php

/** @var rex_addon $this */

use FriendsOfRedaxo\VTrans\VTrans;
use FriendsOfRedaxo\VTrans\VTransConnection;

$normalizeString = static function (mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
};
$normalizeInt = static function (mixed $value, int $default = 0): int {
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int) $value;
    }

    if (is_string($value) && is_numeric($value)) {
        return (int) $value;
    }

    return $default;
};

$normalizeDisplayData = null;
$normalizeDisplayData = static function (mixed $value) use (&$normalizeDisplayData): mixed {
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[is_string($key) ? $key : (string) $key] = $normalizeDisplayData($item);
        }
        return $normalized;
    }

    if (is_object($value)) {
        return null;
    }

    return $value;
};

// Build connection selector and resolve fallback connection.
$playgroundConnections = VTransConnection::getAllPlayground();
$defaultConnection = VTransConnection::getDefaultPlayground();
$defaultConnectionKey = $defaultConnection?->getKey();

// Build lookup by key for quick access.
$connectionsByKey = [];
foreach ($playgroundConnections as $connection) {
    $connectionsByKey[$connection->getKey()] = $connection;
}

$connectionOptions = [];
foreach ($playgroundConnections as $connection) {
    $optionLabel = $connection->getLabel() . ' (' . $connection->getProvider() . ')';
    if ($connection->isDefault()) {
        $optionLabel .= ' DEFAULT';
    }
    $connectionOptions[$connection->getKey()] = $optionLabel;
}

$requestedConnectionKey = rex_request('connection', 'string', '');
$requestConnectionKey = '' !== $requestedConnectionKey ? $requestedConnectionKey : $defaultConnectionKey;
$requestedTargetLang = trim(rex_request('lang_target', 'string', ''));
$availableTargetLangs = VTrans::getAvailableTargetLanguages($requestConnectionKey);
if ('' !== $requestedTargetLang) {
    $defaultTargetLang = VTrans::resolveClosestTargetLanguage($requestedTargetLang, $availableTargetLangs);
} else {
    $defaultTargetLang = VTrans::getDefaultTargetLanguage($requestConnectionKey);
    if ([] !== $availableTargetLangs && !array_key_exists($defaultTargetLang, $availableTargetLangs)) {
        $defaultTargetLang = VTrans::resolveClosestTargetLanguage($defaultTargetLang, $availableTargetLangs);
    }
}

$getConnectionDebugDefault = static function (?string $connectionKey, array $connectionsByKey): bool {
    if (null === $connectionKey || '' === trim($connectionKey) || !isset($connectionsByKey[$connectionKey])) {
        return false;
    }

    $connection = $connectionsByKey[$connectionKey];
    if (!$connection instanceof VTransConnection) {
        return false;
    }

    return $connection->isDebug();
};

$defaultDebugEnabled = $getConnectionDebugDefault($requestConnectionKey, $connectionsByKey);

$isAdmin = null !== rex::getUser() && rex::getUser()->isAdmin();

// Accept GET for prefill (e.g. from data) and POST for actual submits.

$defaultText = 'Hallo Welt! Hallo REDAXO Freunde!';

// Fun REDAXO text snippets for the x1000 generator.
$redaxoTextSnippets = [
    'REDAXO wurde im Jahr 2004 geboren – damals war PHP noch jung und voller Traeume.',
    'Ein REDAXO-Entwickler braucht kein Frontend-Framework. Er hat rex_fragment und guten Willen.',
    'In REDAXO gibt es keine Bugs. Nur undokumentierte Features, die auf Entdeckung warten.',
    'Yakamara hat REDAXO erschaffen. Die Community hat es unsterblich gemacht.',
    'REDAXO ist wie ein Schweizer Taschenmesser – nur dass es auch noch gut aussieht.',
    'AddOns sind die Seele von REDAXO. Ohne sie waere es nur ein huebsches Datenbankformular.',
    'Der REDAXO-Installer ist wie ein App Store, nur ohne fragwuerdige In-App-Kaeufe.',
    'Wer REDAXO einmal verstanden hat, will nie wieder zurueck zu WordPress. Das ist wissenschaftlich erwiesen.',
    'Module und Templates – die Yin-und-Yang-Philosophie von REDAXO seit ueber 20 Jahren.',
    'REDAXO-Meetups: Wo Entwickler sich treffen, Pizza essen und ueber rex_sql philosophieren.',
    'Fun Fact: REDAXO 5 wurde komplett neu geschrieben. Das nennt man Mut. Oder Wahnsinn. Oder beides.',
    'rex_clang – weil eine Sprache nie genug ist, wenn die Welt dein Publikum ist.',
    'In REDAXO kann man alles ueberschreiben. Extension Points sind die Magie dahinter.',
    'Die REDAXO-Community auf Slack ist wie eine grosse Familie. Mit Code-Reviews statt Familienfeiern.',
    'YForm ist das AddOn, das Forms endlich formidabel macht. Wortspiel beabsichtigt.',
    'REDAXO braucht keine 500 Plugins. Es braucht die richtigen 5 AddOns und einen guten Kaffee.',
    'Der Medienpool von REDAXO: Wo Bilder ein Zuhause finden und SEO-Optimierung beginnt.',
    'rex_effect_resize – weil nicht jedes Bild 4000 Pixel breit sein muss, liebe Fotografen.',
    'REDAXO-Entwickler debuggen nicht. Sie haben intensive Gespraeche mit dem Error-Log.',
    'Ein Content Management System sollte den Content managen, nicht den Entwickler. REDAXO hat das verstanden.',
    'Backup-AddOn: Weil auch ein CMS manchmal eine Versicherung braucht. Safety first!',
    'REDAXO 4 war gut. REDAXO 5 war besser. REDAXO 6? Die Community arbeitet daran.',
    'rex_config::get() – Der freundlichste Getter seit Erfindung der Objektorientierung.',
    'In REDAXO ist der Backend-Login so sicher wie Fort Knox. Nur ohne das Gold.',
    'Friends Of REDAXO: Wo Open Source und Freundschaft zusammentreffen und grossartigen Code produzieren.',
    'Der REDAXO-Table-Manager heisst jetzt YForm. Rebranding auf hoechstem Niveau.',
    'Meta-Infos in REDAXO: Weil jeder Artikel eine Geschichte hat, die erzaehlt werden will.',
    'REDAXO auf dem Server installieren dauert 5 Minuten. Es danach nicht mehr benutzen zu wollen dauert ewig.',
    'Cronjobs in REDAXO laufen puenktlicher als die Deutsche Bahn. Keine hohe Messlatte, aber immerhin.',
    'REDAXO-Slices: Wie LEGO-Steine fuer Erwachsene, die Webseiten bauen statt Burgen.',
    'Wusstest du? REDAXO steht fuer Redaktion und Export. Je mehr du weisst, desto mehr liebst du es.',
    'Der Struktur-Manager von REDAXO: Baumansichten, die sogar Foerster neidisch machen wuerden.',
    'PHP und REDAXO – eine Liebesgeschichte, die seit ueber zwei Jahrzehnten andauert.',
    'REDAXO-Dokumentation: Von der Community geschrieben, fuer die Community gepflegt, von allen geliebt.',
    'In REDAXO gibt es rex_extension. Damit kann man alles haken. Wie Angeln, nur produktiver.',
];

$requestData = [
    'connection' => (string) ($requestConnectionKey ?? ''),
    'key' => $isAdmin ? rex_request('key', 'string', '') : '',
    'lang_source' => rex_request('lang_source', 'string', 'auto'),
    'lang_target' => $defaultTargetLang,
    'format' => rex_request('format', 'string', 'text'),
    'context' => rex_request('context', 'string', ''),
    'custom_instructions' => rex_request('custom_instructions', 'string', ''),
    'text' => rex_request('text', 'string', $defaultText),
    'debug' => $isAdmin && 1 === rex_request('debug', 'int', $defaultDebugEnabled ? 1 : 0),
    'nocache' => $isAdmin && 1 === rex_request('nocache', 'int', 1),
];


$retryId = rex_request('retry_id', 'int', 0);
if ($retryId > 0 && !rex_post('config-submit', 'boolean')) {
    try {
        $retrySql = rex_sql::factory();
        $retrySql->setQuery('SELECT connection, `key`, source, target, `format`, prompt, custom_instructions, `text` FROM ' . rex::getTable('vtrans') . ' WHERE id = ? LIMIT 1', [$retryId]);

        if ($retrySql->getRows() > 0) {
            $requestData['connection'] = (string) ($retrySql->getValue('connection') ?? '');
            $requestData['key'] = (string) ($retrySql->getValue('key') ?? '');
            $requestData['lang_source'] = '' !== trim((string) ($retrySql->getValue('source') ?? '')) ? (string) $retrySql->getValue('source') : 'auto';
            $retryLangTarget = (string) ($retrySql->getValue('target') ?? '');
            $retryConnectionKey = $requestData['connection'];
            if ('' !== $retryLangTarget && '' !== $retryConnectionKey) {
                $retryAvailableLangs = VTrans::getAvailableTargetLanguages($retryConnectionKey);
                $requestData['lang_target'] = VTrans::resolveClosestTargetLanguage(strtolower($retryLangTarget), $retryAvailableLangs);
            } elseif ('' !== $retryLangTarget) {
                $requestData['lang_target'] = strtolower($retryLangTarget);
            }
            $requestData['format'] = (string) ($retrySql->getValue('format') ?? 'text');
            $requestData['context'] = (string) ($retrySql->getValue('prompt') ?? '');
            $retryCustomInstructions = (string) ($retrySql->getValue('custom_instructions') ?? '');
            if ('' !== trim($retryCustomInstructions)) {
                $decodedRetryCustomInstructions = json_decode($retryCustomInstructions, true);
                if (is_array($decodedRetryCustomInstructions)) {
                    $requestData['custom_instructions'] = implode("\n", array_filter(array_map(static function (mixed $line): string {
                        return is_scalar($line) ? (string) $line : '';
                    }, $decodedRetryCustomInstructions), static function (string $line): bool {
                        return '' !== trim($line);
                    }));
                } else {
                    $requestData['custom_instructions'] = $retryCustomInstructions;
                }
            }
            $requestData['text'] = (string) ($retrySql->getValue('text') ?? '');

            if ('' !== $requestData['connection']) {
                $requestData['debug'] = $getConnectionDebugDefault($requestData['connection'], $connectionsByKey);
            }
        }
    } catch (Throwable $e) {
        // Keep request values if retry prefill cannot be loaded.
    }
}

$usageData = null;
$usageError = null;
$usageConnectionKey = '' !== $requestData['connection'] ? $requestData['connection'] : $defaultConnectionKey;

if (null !== $usageConnectionKey && isset($connectionsByKey[$usageConnectionKey])) {
    try {
        $usageData = VTrans::getUsage($usageConnectionKey);
    } catch (Throwable $e) {
        $usageError = $e->getMessage();
    }
}

if ([] === $playgroundConnections) {
    echo rex_view::warning($this->i18n('vtrans_no_connections'));
    return;
}

$shouldTranslate = '1' === rex_post('config-submit', 'string', '') && '' !== trim($requestData['text']);
$translationError = null;
$translatedText = null;
$cacheHit = false;
$entryId = 0;
$tokenUsage = null;
$rateLimit = null;
$cacheMode = 'default';
/** @var array<string, mixed> $lastResultMeta */
$lastResultMeta = [];
/** @var array<string, mixed> $entryData */
$entryData = [];
$effectiveConnection = '' !== $requestData['connection'] ? $requestData['connection'] : ($defaultConnectionKey ?? '-');
$selectedConnectionKey = '' !== $requestData['connection'] ? $requestData['connection'] : $defaultConnectionKey;
$connectionSupportsPromptOptions = VTrans::connectionSupportsPromptOptions($selectedConnectionKey);

// Trigger translation only on explicit submit.
if ($shouldTranslate) {
    try {
        $translatedText = VTrans::translate(
            $requestData['text'],
            $requestData['lang_source'],
            $requestData['lang_target'],
            $requestData['format'],
            '' !== $requestData['key'] ? $requestData['key'] : null,
            [
                'connection' => '' !== $requestData['connection'] ? $requestData['connection'] : null,
                'context' => $connectionSupportsPromptOptions ? $requestData['context'] : '',
                'customInstructions' => $connectionSupportsPromptOptions ? $requestData['custom_instructions'] : '',
                'debug' => $requestData['debug'],
                'cache' => !$requestData['nocache'],
            ]
        );

        /** @var array<string, mixed> $lastResultMeta */
        $lastResultMeta = VTrans::getLastResultMeta();
        /** @var array<string, mixed> $entryData */
        $entryData = VTrans::getLastResultData();
        $cacheHit = (bool) ($lastResultMeta['cached'] ?? false);
        $cacheMode = $normalizeString($lastResultMeta['cacheMode'] ?? 'default');
        $entryId = $normalizeInt($lastResultMeta['id'] ?? 0);
        if (isset($entryData['usage']) && is_array($entryData['usage'])) {
            $tokenUsage = $entryData['usage'];
        }
        if (isset($entryData['rate_limit']) && is_array($entryData['rate_limit'])) {
            $rateLimit = $entryData['rate_limit'];
        }

        if ($entryId > 0) {
            try {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT data FROM ' . rex::getTable('vtrans') . ' WHERE id = ?', [$entryId]);
                $entryDataRaw = (string) $sql->getValue('data');
                if ('' !== trim($entryDataRaw)) {
                    $storedEntryData = json_decode($entryDataRaw, true);
                    if (is_array($storedEntryData)) {
                        $normalizedEntryData = $normalizeDisplayData($storedEntryData);
                        if (is_array($normalizedEntryData)) {
                            /** @var array<string, mixed> $entryData */
                            $entryData = $normalizedEntryData;
                            if (isset($entryData['usage']) && is_array($entryData['usage'])) {
                                $tokenUsage = $entryData['usage'];
                            }
                            if (isset($entryData['rate_limit']) && is_array($entryData['rate_limit'])) {
                                $rateLimit = $entryData['rate_limit'];
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore optional token/rate-limit loading failures in testing UI.
            }
        }

        if (null !== $usageConnectionKey && isset($connectionsByKey[$usageConnectionKey])) {
            try {
                $usageData = VTrans::getUsage($usageConnectionKey);
                $usageError = null;
            } catch (Throwable $e) {
                $usageError = $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $translationError = $e->getMessage();
    }
}

$formElements = [];

$connectionSelectHtml = '<select name="connection" id="rex-form-connection" class="form-control selectpicker" onchange="$(this.form).trigger(\'submit\')">';
foreach ($connectionOptions as $connectionKey => $optionLabel) {
    $selected = (string) $requestData['connection'] === (string) $connectionKey ? ' selected="selected"' : '';
    $value = rex_escape((string) $connectionKey);
    $text = rex_escape((string) $optionLabel);
    $connectionMaxCharsAttr = '';
    if ('' !== (string) $connectionKey && isset($connectionsByKey[(string) $connectionKey])) {
        $connectionMaxCharsVal = $connectionsByKey[(string) $connectionKey]->getMaxChars();
        $connectionMaxCharsAttr = ' data-max-chars="' . (null !== $connectionMaxCharsVal ? (int) $connectionMaxCharsVal : '') . '"';
    }

    if ('' !== (string) $connectionKey && isset($connectionsByKey[(string) $connectionKey]) && $connectionsByKey[(string) $connectionKey]->isDefault()) {
        $labelWithoutSuffix = preg_replace('/\s+DEFAULT$/', '', (string) $optionLabel);
        $content = rex_escape((string) ($labelWithoutSuffix ?? $optionLabel))
            . ' <span class="badge" style="margin-left:6px; padding:4px; padding-right:8px;"><i class="fa fa-dot-circle-o text-success" title="' . rex_escape($this->i18n('vtrans_connections_default')) . '"></i> ' . $this->i18n('vtrans_connections_default') . '</span>';
        $connectionSelectHtml .= '<option value="' . $value . '" data-content="' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '"' . $connectionMaxCharsAttr . $selected . '>' . $text . '</option>';
        continue;
    }

    $connectionSelectHtml .= '<option value="' . $value . '"' . $connectionMaxCharsAttr . $selected . '>' . $text . '</option>';
}
$connectionSelectHtml .= '</select>';

$n = [];
$n['label'] = '<label for="rex-form-connection"><i class="rex-icon fa-plug"></i> ' . $this->i18n('vtrans_connection') . '</label>';
$formatSelect = new rex_select();
$formatSelect->setName('format');
$formatSelect->setId('rex-form-format');
$formatSelect->setAttribute('class', 'form-control selectpicker');
$formatSelect->setMultiple(false);
$formatSelect->addOptions(['text' => $this->i18n('vtrans_format') . ': Text', 'html' => $this->i18n('vtrans_format') . ': HTML']);
$formatSelect->setSelected($requestData['format']);

$n['field'] = '<div style="display:flex; gap:10px; align-items:flex-start;">'
    . '<div style="flex:1">' . $connectionSelectHtml . '</div>'
    . '<div style="flex-shrink:0"><label for="rex-form-format" class="sr-only">' . $this->i18n('vtrans_format') . '</label>' . $formatSelect->get() . '</div>'
    . '</div>';

$usageSummary = '';
$usageProgressHtml = '';
$usageSummaryClass = 'text-muted';
if (null === $usageConnectionKey || !isset($connectionsByKey[$usageConnectionKey])) {
    $usageSummary = $this->i18n('vtrans_usage_no_connection');
} elseif (null !== $usageError) {
    $usageSummary = $this->i18n('vtrans_usage_error') . ': ' . $usageError;
    $usageSummaryClass = 'text-danger';
} elseif (!is_array($usageData)) {
    $usageSummary = $this->i18n('vtrans_usage_unavailable');
} elseif (!isset($usageData['character']) || !is_array($usageData['character'])) {
    $usageSummary = $this->i18n('vtrans_usage_no_char_limit');
} else {
    $character = $usageData['character'];
    $count = $normalizeInt($character['count'] ?? 0);
    $limit = $normalizeInt($character['limit'] ?? 0);
    $remaining = $normalizeInt($character['remaining'] ?? 0);
    $usagePercent = $limit > 0 ? (int) round(($count / $limit) * 100) : 0;
    $usagePercent = max(0, min(100, $usagePercent));

    $barColor = '#5cb85c';
    if ($count >= $limit && $limit > 0) {
        $barColor = '#d9534f';
    } elseif ($usagePercent >= 80) {
        $barColor = '#f0ad4e';
    }

    $usageProgressHtml = '<div style="margin-top:5px; width:100%;">'
        . '<div style="height:7px; width:100%; background:#ececec; border-radius:7px; overflow:hidden;">'
        . '<div style="height:7px; width:' . $usagePercent . '%; background:' . $barColor . '; transition:width .25s ease;"></div>'
        . '</div>'
        . '</div>';

    $usageSummary = $this->i18n('vtrans_usage_chars') . ': '
        . (string) $count . ' / ' . (string) $limit
        . ' (' . $this->i18n('vtrans_remaining') . ': ' . (string) $remaining . ')';
}

$usageControls = '<div class="small ' . $usageSummaryClass . '" style="margin-top:4px">'
    . rex_escape($this->i18n('vtrans_usage_prefix')) . ' '
    . rex_escape((string) $usageSummary)
    . '</div>'
    . $usageProgressHtml;

$n['note'] = $usageControls;


$formElements[] = $n;




$selectSource = new rex_select();
$selectSource->setName('lang_source');
$selectSource->setId('rex-form-lang-source');
$selectSource->setAttribute('class', 'form-control selectpicker');
$selectSource->setMultiple(false);
$selectSource->addOptions(VTrans::getAvailableSourceLanguages($selectedConnectionKey));
$selectSource->setSelected($requestData['lang_source']);

$selectTarget = new rex_select();
$selectTarget->setName('lang_target');
$selectTarget->setId('rex-form-lang-target');
$selectTarget->setAttribute('class', 'form-control selectpicker');
$selectTarget->setMultiple(false);
$selectTarget->addOptions(VTrans::getAvailableTargetLanguages($selectedConnectionKey));
$selectTarget->setSelected($requestData['lang_target']);

$n = [];
$n['label'] = '<label>' . $this->i18n('vtrans_lang_source_target') . '</label>';
$n['field'] = '<div style="display:flex; gap:10px;"><div style="flex:1">' . $selectSource->get() . '</div><div style="padding-top:5px;"> <i class="rex-icon fa-arrow-right"></i> </div><div style="flex:1">' . $selectTarget->get() . '</div></div>';
$formElements[] = $n;


$playgroundGlobalMaxChars = VTrans::GLOBAL_MAX_CHARS;
$redaxoTextSnippetsJson = json_encode($redaxoTextSnippets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$randJokeBtn = '<div><br /><button type="button" id="vtrans-joke-btn" class="btn btn-sm btn-default" style="margin-right:4px;">'
    . '<i class="rex-icon fa-smile-o"></i> </button>'
    . '<button type="button" id="vtrans-x1000-btn" class="btn btn-sm btn-default" style="margin-right:4px;">'
    . '<i class="rex-icon fa-file-text-o"></i> x1000</button>'
    . '<button type="button" id="vtrans-clear-btn" class="btn btn-sm btn-default">'
    . '<i class="rex-icon fa-eraser"></i></button></div>'
    . '<script>(function () {
            var jokeBtn = document.getElementById("vtrans-joke-btn");
            var x1000Btn = document.getElementById("vtrans-x1000-btn");
            var clearBtn = document.getElementById("vtrans-clear-btn");
            var connectionSel = document.getElementById("rex-form-connection");
            var GLOBAL_LIMIT = ' . (int) $playgroundGlobalMaxChars . ';
            var DEFAULT_TEXT = ' . json_encode($defaultText, JSON_UNESCAPED_UNICODE) . ';
            var SNIPPETS = ' . $redaxoTextSnippetsJson . ';
            var FILL_WORDS = [" REDAXO", " CMS", " Code", " PHP", " Web", " AddOn", " 42", " rx5", " dev"];

            // Lazy lookup – textarea is rendered AFTER this script tag.
            function getTextarea() { return document.getElementById("rex-form-text"); }

            function triggerInput() {
                var ta = getTextarea();
                if (ta) ta.dispatchEvent(new Event("input", { bubbles: true }));
            }

            function getLimit() {
                if (!connectionSel) return GLOBAL_LIMIT;
                var opt = connectionSel.options[connectionSel.selectedIndex];
                var mc = opt ? opt.getAttribute("data-max-chars") : "";
                return (mc !== null && mc !== "") ? parseInt(mc, 10) : GLOBAL_LIMIT;
            }

            function updateX1000State() {
                var ta = getTextarea();
                if (!x1000Btn || !ta) return;
                x1000Btn.disabled = ta.value.length >= getLimit();
            }

            /* ---- Joke button ---- */
            if (jokeBtn && !jokeBtn._vtransJokeInit) {
                jokeBtn._vtransJokeInit = true;
                jokeBtn.addEventListener("click", function () {
                    var supported = ["cs", "de", "en", "es", "fr", "pt"];
                    var sel = document.getElementById("rex-form-lang-source");
                    var raw = sel ? sel.value : "";
                    var code = raw.toLowerCase().replace(/[-_].*$/, "");
                    var lang = supported.indexOf(code) !== -1 ? code : "de";
                    jokeBtn.disabled = true;
                    fetch("https://v2.jokeapi.dev/joke/Any?lang=" + lang)
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d.error) return;
                            var t = d.type === "twopart" ? d.setup + "\n\n" + d.delivery : d.joke;
                            var ta = getTextarea();
                            if (ta) { ta.value = t; triggerInput(); }
                        })
                        .catch(function () { })
                        .finally(function () { jokeBtn.disabled = false; updateX1000State(); });
                });
            }

            /* ---- x1000 button ---- */
            if (x1000Btn && !x1000Btn._vtransX1000Init) {
                x1000Btn._vtransX1000Init = true;
                x1000Btn.addEventListener("click", function () {
                    var ta = getTextarea();
                    if (!ta) return;
                    var limit = getLimit();
                    var currentLen = ta.value.length;
                    if (currentLen >= limit) return;

                    // Clear default text on first use.
                    if (ta.value.trim() === DEFAULT_TEXT) { ta.value = ""; currentLen = 0; }

                    // Target = next multiple of 1000, capped at the connection limit.
                    var target = Math.ceil((currentLen + 1) / 1000) * 1000;
                    if (target <= currentLen) target = currentLen + 1000;
                    if (target > limit) target = limit;

                    // How many chars do we need to add? Account for separator.
                    var separator = currentLen > 0 ? "\n\n" : "";
                    var needed = target - currentLen - separator.length;
                    if (needed <= 0) { triggerInput(); updateX1000State(); return; }

                    // Build filler from random snippets up to the needed length.
                    var block = "";
                    var used = [];
                    var safety = 0;
                    while (block.length < needed && safety < 500) {
                        safety++;
                        var idx = Math.floor(Math.random() * SNIPPETS.length);
                        if (used.indexOf(idx) !== -1 && used.length < SNIPPETS.length) continue;
                        used.push(idx);
                        block += (block.length > 0 ? " " : "") + SNIPPETS[idx];
                        if (used.length >= SNIPPETS.length) used = [];
                    }

                    // Trim or pad to exactly the needed length.
                    if (block.length > needed) {
                        block = block.substring(0, needed);
                    } else {
                        while (block.length < needed) {
                            var fill = FILL_WORDS[Math.floor(Math.random() * FILL_WORDS.length)];
                            if (block.length + fill.length <= needed) {
                                block += fill;
                            } else {
                                block += fill.substring(0, needed - block.length);
                            }
                        }
                    }

                    ta.value += separator + block;
                    triggerInput();
                    updateX1000State();
                });
            }

            /* ---- Clear button ---- */
            if (clearBtn && !clearBtn._vtransClearInit) {
                clearBtn._vtransClearInit = true;
                clearBtn.addEventListener("click", function () {
                    var ta = getTextarea();
                    if (ta) { ta.value = ""; triggerInput(); }
                    updateX1000State();
                });
            }

            /* ---- Deferred monitoring – attach after textarea exists ---- */
            function initMonitoring() {
                var ta = getTextarea();
                if (!ta) { setTimeout(initMonitoring, 50); return; }
                ta.addEventListener("input", updateX1000State);
                updateX1000State();
            }
            if (connectionSel) connectionSel.addEventListener("change", function () { setTimeout(updateX1000State, 50); });
            initMonitoring();
        }());</script>';


// Determine the effective max_chars for the currently selected connection (for JS).
$playgroundMaxChars = VTrans::GLOBAL_MAX_CHARS;
if (null !== $selectedConnectionKey && isset($connectionsByKey[$selectedConnectionKey])) {
    $selectedConnectionMaxChars = $connectionsByKey[$selectedConnectionKey]->getMaxChars();
    if (null !== $selectedConnectionMaxChars && $selectedConnectionMaxChars > 0) {
        $playgroundMaxChars = $selectedConnectionMaxChars;
    }
}

$charCounterJs = '<script>(function () {
        var GLOBAL_LIMIT = ' . (int) $playgroundGlobalMaxChars . ';
        var textarea = document.getElementById("rex-form-text");
        var counter = document.getElementById("vtrans-char-counter");
        var connectionSel = document.getElementById("rex-form-connection");
        if (!textarea || !counter) return;

        function getLimit() {
            if (!connectionSel) return GLOBAL_LIMIT;
            var opt = connectionSel.options[connectionSel.selectedIndex];
            var mc = opt ? opt.getAttribute("data-max-chars") : "";
            return (mc !== null && mc !== "") ? parseInt(mc, 10) : GLOBAL_LIMIT;
        }

        function update() {
            var len = textarea.value.length;
            var limit = getLimit();
            var pct = Math.min(100, Math.round(len / limit * 100));
            var color = len > limit ? "#d9534f" : (pct >= 90 ? "#f0ad4e" : (pct >= 70 ? "#5b9bd5" : "#777"));
            counter.innerHTML =
                "<span style=\"color:" + color + "; font-weight:" + (len > limit ? "bold" : "normal") + "\">" +
                len.toLocaleString() + " / " + limit.toLocaleString() +
                (len > limit ? " &mdash; <strong>' . addslashes($this->i18n('vtrans_chars_exceeded')) . '</strong>" : "") +
                "</span>";
        }

        textarea.addEventListener("input", update);
        if (connectionSel) {
            connectionSel.addEventListener("change", update);
            // selectpicker fires bs-select events after DOM change
            connectionSel.addEventListener("loaded.bs.select", update);
        }
        update();
    }());</script>';

$n = [];
$n['label'] = '<label for="rex-form-text">' . $this->i18n('vtrans_text') . $randJokeBtn . '</label>';
$n['field'] = '<textarea style="max-width:100%" rows="6" class="form-control" id="rex-form-text" name="text">' . rex_escape($requestData['text']) . '</textarea>'
    . '<div id="vtrans-char-counter" style="margin-top:4px; font-size:12px; text-align:right;"></div>'
    . $charCounterJs;

$formElements[] = $n;

$advancedSettingsActive = ('' !== trim((string) $requestData['key']))
    || ('' !== trim((string) $requestData['context']))
    || ('' !== trim((string) $requestData['custom_instructions']));

$advancedElements = [];

if ($isAdmin) {
    $n = [];
    $n['label'] = '<label for="rex-form-key">' . $this->i18n('vtrans_entry_key') . '</label>';
    $n['field'] = '<input type="text" class="form-control" id="rex-form-key" name="key" value="' . rex_escape($requestData['key']) . '" />';
    $n['note'] = '<p class="help-block">' . $this->i18n('vtrans_entry_key_note') . '</p>';
    $advancedElements[] = $n;
}

if ($connectionSupportsPromptOptions) {
    $n = [];
    $n['label'] = '<label for="rex-form-context">' . $this->i18n('vtrans_context') . '</label>';
    $n['field'] = '<textarea style="max-width:100%" rows="4" class="form-control" id="rex-form-context" name="context">' . rex_escape($requestData['context']) . '</textarea>';
    $n['note'] = '<p class="help-block">' . $this->i18n('vtrans_context_note') . '</p>';
    $advancedElements[] = $n;

    $n = [];
    $n['label'] = '<label for="rex-form-custom-instructions">' . $this->i18n('vtrans_custom_instructions') . '</label>';
    $n['field'] = '<textarea style="max-width:100%" rows="4" class="form-control" id="rex-form-custom-instructions" name="custom_instructions">' . rex_escape($requestData['custom_instructions']) . '</textarea>';
    $n['note'] = '<p class="help-block">' . $this->i18n('vtrans_custom_instructions_note') . '</p>';
    $advancedElements[] = $n;
}



$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content = $fragment->parse('core/form/form.php');

if ([] !== $advancedElements) {
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $advancedElements, false);
    $advancedContent = $fragment->parse('core/form/form.php');
    $collapseId = 'vtrans-playground-advanced';
    $collapseIn = $advancedSettingsActive ? ' in' : '';
    $content .= '<div class="panel panel-default" style="margin-top:30px;">'
        . '<div class="panel-heading" style="padding:0;">'
        . '<a data-toggle="collapse" href="#' . $collapseId . '" aria-expanded="' . ($advancedSettingsActive ? 'true' : 'false') . '" style="display:block; padding:8px 15px; color:inherit; text-decoration:none;">'
        . '<i class="rex-icon fa-cog" style="margin-right:6px;"></i>'
        . $this->i18n('vtrans_advanced_settings')
        . ' <i class="rex-icon fa-chevron-down" style="float:right; margin-top:2px; font-size:0.85em;"></i>'
        . '</a>'
        . '</div>'
        . '<div id="' . $collapseId . '" class="collapse' . $collapseIn . '">'
        . '<div class="panel-body" style="padding-bottom:0;">'
        . $advancedContent
        . '</div>'
        . '</div>'
        . '</div>';
}

$footerCheckboxes = '';
if ($isAdmin) {
    $footerCheckboxes = '<div style="display:flex; align-items:center; gap:16px;">'
        . '<label style="margin:0; font-weight:normal; cursor:pointer;" title="' . rex_escape($this->i18n('vtrans_nocache_note')) . '">'
        . '<input type="hidden" name="nocache" value="0">'
        . '<input type="checkbox" name="nocache" value="1"' . ($requestData['nocache'] ? ' checked' : '') . '> No Cache'
        . '</label>'
        . '<label style="margin:0; font-weight:normal; cursor:pointer;" title="' . rex_escape($this->i18n('vtrans_debug_note')) . '">'
        . '<input type="hidden" name="debug" value="0">'
        . '<input type="checkbox" name="debug" value="1"' . ($requestData['debug'] ? ' checked' : '') . '> Debug'
        . '</label>'
        . '</div>';
}
$buttons = '<div style="display:flex; justify-content:' . ($isAdmin ? 'space-between' : 'flex-end') . '; align-items:center;">'
    . $footerCheckboxes
    . '<button class="btn btn-save" type="submit" name="config-submit" value="1"><i class="rex-icon fa-paper-plane"></i> ' . $this->i18n('vtrans_translate') . '</button>'
    . '</div>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit');
$fragment->setVar('title', $this->i18n('vtrans_playground_title'));
$fragment->setVar('body', $content, false);
$fragment->setVar('buttons', $buttons, false);
$content = $fragment->parse('core/page/section.php');

echo '<form action="' . rex_url::currentBackendPage() . '" method="post" data-pjax="true" data-pjax-no-history="true" data-pjax-scroll-to="false">' . $content . '</form>';

if ($shouldTranslate) {
    if (null === $translationError) {
        $resultElements = [];
        $resultMetaLines = [
            '<span class="label label-default">' . rex_escape((string) $effectiveConnection) . '</span>',
            $this->i18n('vtrans_key') . ': ' . rex_escape('' !== trim((string) $requestData['key']) ? (string) $requestData['key'] : '-'),
            $this->i18n('vtrans_source') . ': ' . rex_escape((string) $requestData['lang_source']),
            $this->i18n('vtrans_target') . ': ' . rex_escape((string) $requestData['lang_target']),
            $this->i18n('vtrans_format') . ': ' . rex_escape((string) $requestData['format'])
        ];

        if (is_array($tokenUsage)) {
            $promptTokens = $normalizeInt($tokenUsage['prompt_tokens'] ?? 0);
            $completionTokens = $normalizeInt($tokenUsage['completion_tokens'] ?? 0);
            $totalTokens = $normalizeInt($tokenUsage['total_tokens'] ?? ($promptTokens + $completionTokens));
            $resultMetaLines[] = $this->i18n('vtrans_tokens') . ': '
                . $this->i18n('vtrans_tokens_prompt') . ' ' . (string) $promptTokens . ', '
                . $this->i18n('vtrans_tokens_completion') . ' ' . (string) $completionTokens . ', '
                . $this->i18n('vtrans_tokens_total') . ' ' . (string) $totalTokens;
        }

        if (is_array($rateLimit)) {
            $requestRateText = [];
            if (isset($rateLimit['requests_limit'])) {
                $requestRateText[] = $this->i18n('vtrans_rate_limit_limit') . ' ' . rex_escape($normalizeString($rateLimit['requests_limit']));
            }
            if (isset($rateLimit['requests_remaining'])) {
                $requestRateText[] = $this->i18n('vtrans_remaining') . ' ' . rex_escape($normalizeString($rateLimit['requests_remaining']));
            }
            if (isset($rateLimit['requests_reset'])) {
                $requestRateText[] = $this->i18n('vtrans_rate_limit_reset') . ' ' . rex_escape($normalizeString($rateLimit['requests_reset']));
            }

            $tokenRateText = [];
            if (isset($rateLimit['tokens_limit'])) {
                $tokenRateText[] = $this->i18n('vtrans_rate_limit_limit') . ' ' . rex_escape($normalizeString($rateLimit['tokens_limit']));
            }
            if (isset($rateLimit['tokens_remaining'])) {
                $tokenRateText[] = $this->i18n('vtrans_remaining') . ' ' . rex_escape($normalizeString($rateLimit['tokens_remaining']));
            }
            if (isset($rateLimit['tokens_reset'])) {
                $tokenRateText[] = $this->i18n('vtrans_rate_limit_reset') . ' ' . rex_escape($normalizeString($rateLimit['tokens_reset']));
            }

            if ([] !== $requestRateText) {
                $resultMetaLines[] = $this->i18n('vtrans_rate_limit') . ' ' . $this->i18n('vtrans_rate_limit_requests') . ': ' . implode(', ', $requestRateText);
            }

            if ([] !== $tokenRateText) {
                $resultMetaLines[] = $this->i18n('vtrans_rate_limit') . ' ' . $this->i18n('vtrans_rate_limit_tokens') . ': ' . implode(', ', $tokenRateText);
            }
        }

        $durationMeta = null;
        if (array_key_exists('durationMs', $lastResultMeta)) {
            $durationMeta = $lastResultMeta['durationMs'];
        }
        $durationMs = $normalizeInt($durationMeta, 0);
        if (is_int($durationMeta) || is_float($durationMeta) || (is_string($durationMeta) && is_numeric($durationMeta))) {
            $durationSec = $durationMs / 1000;
            $durationFormatted = number_format($durationSec, 2, '.', '') . 's';
            $inputLength = mb_strlen((string) $requestData['text']);
            $speedLine = 'Duration: ' . $durationFormatted;
            if (!$cacheHit && 1 === preg_match('/./u', (string) $requestData['text'])) {
                $secPer1k = $durationSec / ($inputLength / 1000);
                $speedLine .= ' &mdash; ' . number_format($secPer1k, 2, '.', '') . 's / 1k chars';
            }
            $resultMetaLines[] = $speedLine;
        }

        $n = [];
        $n['label'] = '<small>'
            . implode('<br />', $resultMetaLines)
            . '</small>';
        $n['field'] = '<textarea style="max-width:100%" rows="6" class="form-control" id="rex-form-translated-text">' . rex_escape((string) $translatedText) . '</textarea>';
        $resultElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $resultElements, false);
        $resultContent = $fragment->parse('core/form/form.php');

        $resultFooter = '';
        if ($entryId > 0) {
            $detailsUrl = rex_url::backendPage('vtrans/data', ['func' => 'edit', 'id' => $entryId]);
            $resultFooter = '<div class="text-right"><a class="btn btn-default btn-xs" href="' . $detailsUrl . '">' . rex_escape($this->i18n('vtrans_result_details')) . '</a></div>';
        }

        $fragment = new rex_fragment();
        $fragment->setVar('class', 'info');
        $resultTitle = $this->i18n('vtrans_result_title') . ($entryId > 0 ? ' #' . $entryId : '');
        if ('no-cache' === $cacheMode) {
            $resultTitle .= ' <span class="badge pull-right">' . rex_escape($this->i18n('vtrans_nocache_badge')) . '</span>';
        } elseif ($cacheHit) {
            $resultTitle .= ' <span class="badge pull-right">cached</span>';
        }
        $fragment->setVar('title', $resultTitle, false);
        $fragment->setVar('body', $resultContent, false);
        $fragment->setVar('footer', $resultFooter, false);
        $resultContent = $fragment->parse('core/page/section.php');
        echo $resultContent;

        $debugPayload = null;
        if (array_key_exists('_debug', $entryData)) {
            $debugPayload = $entryData['_debug'];
        }
        if ($requestData['debug'] && is_array($debugPayload)) {
            $debugJson = json_encode($debugPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $debugElements = [];
            $n = [];
            $n['label'] = '';
            $n['field'] = '<pre style="max-height:500px;overflow:auto;background:#f8f8f8;padding:12px;border:1px solid #ddd;border-radius:3px;font-size:12px;white-space:pre-wrap;word-break:break-all">' . rex_escape((string) $debugJson) . '</pre>';
            $debugElements[] = $n;

            $debugFragment = new rex_fragment();
            $debugFragment->setVar('elements', $debugElements, false);
            $debugBody = $debugElements[0]['field'];

            $debugSection = new rex_fragment();
            $debugSection->setVar('class', 'notice');
            $debugSection->setVar('title', $this->i18n('vtrans_debug_result_title'));
            $debugSection->setVar('body', $debugBody, false);
            echo $debugSection->parse('core/page/section.php');
        }

    } else {
        echo rex_view::error($this->i18n('vtrans_error') . ': ' . rex_escape($translationError));

        // Show raw DB data below the error when debug mode is active.
        $errorEntryId = VTrans::getLastEntryId();
        if ($requestData['debug'] && $errorEntryId > 0) {
            try {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT data FROM ' . rex::getTable('vtrans') . ' WHERE id = ?', [$errorEntryId]);
                $errorDataRaw = (string) $sql->getValue('data');
                if ('' !== trim($errorDataRaw)) {
                    $errorEntryData = json_decode($errorDataRaw, true);
                    if (is_array($errorEntryData) && [] !== $errorEntryData) {
                        $errorJson = json_encode($errorEntryData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $errorDebugBody = '<pre style="max-height:500px;overflow:auto;background:#f8f8f8;padding:12px;border:1px solid #ddd;border-radius:3px;font-size:12px;white-space:pre-wrap;word-break:break-all">' . rex_escape((string) $errorJson) . '</pre>';

                        $errorDebugSection = new rex_fragment();
                        $errorDebugSection->setVar('class', 'notice');
                        $errorDebugSection->setVar('title', $this->i18n('vtrans_error_raw_data_title') . ' #' . $errorEntryId);
                        $errorDebugSection->setVar('body', $errorDebugBody, false);
                        echo $errorDebugSection->parse('core/page/section.php');
                    }
                }
            } catch (Throwable $e) {
                // Ignore failures loading raw data for display.
            }
        }
    }
}