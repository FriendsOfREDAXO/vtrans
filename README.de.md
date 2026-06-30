# vTrans BETA für REDAXO 5

vTrans bündelt mehrere Text-Verarbeitungs-APIs hinter einer einheitlichen Schnittstelle,
speichert Ergebnisse in der Datenbank und bringt Backend-Seiten für Test, Analyse und Pflege mit.

Der primäre Einsatzzweck ist Übersetzen. Mit LLM-basierten Providern (z. B. dem OpenAI-Provider)
lassen sich aber auch andere Anwendungsfälle umsetzen, bei denen Quell- und Zielsprache identisch sind –
z. B. Zusammenfassen, Umformulieren oder inhaltliche Bearbeitung von Texten.

> Hinweis: vTrans befindet sich aktuell in einer frühen Beta-Phase. Ein produktiver Einsatz wird nur mit
> entsprechender Prüfung und unter genauer Beobachtung empfohlen.

---

## Installation

Über den REDAXO-Installer (noch nicht verfügbar) installieren oder manuell nach `redaxo/src/addons/vtrans` kopieren und anschließend im Backend aktivieren.

**Voraussetzungen:**
- REDAXO >= 5.17.0
- PHP >= 8.2

---

## Schnellstart

1. Im Backend unter `vTrans -> Connections` eine Verbindung anlegen.
2. Provider auswählen (z. B. `deepl-api-free-v2`, `openai`, `google-translate-basic-v2`).
3. API-Key / API-URL / zusätzliche Parameter eintragen.
4. Optional als Standard und/oder für das Playground markieren.
5. `vTrans -> Playground` öffnen und testen.

Beispiel für eine DeepL-Free-Verbindung:
- Key: `deepl_free`
- Label: `DeepL Free`
- Provider: `deepl-api-free-v2`
- API URL: `https://api-free.deepl.com/v2/translate`
- API Key: `DEIN_DEEPL_KEY`

Hinweise:
- Free-Keys gehören zur Free-API-URL `https://api-free.deepl.com/v2/translate`.
- Die Standard-Connection wird automatisch verwendet, wenn beim Aufruf kein `connection`-Wert übergeben wird.

---

## Funktionen

- Mehrere Provider über eine einheitliche API ansprechen
- Verbindungen zentral im Backend verwalten
- Abrufe manuell im Backend testen (Playground)
- DB Cache wird über String-Hash, Verbindung, Sprache und Format überwacht
- Stabile Keys (optional) für wiederverwendbare Inhalte
- Gespeicherte Datensätze unter `Daten` suchen, filtern, prüfen und bearbeiten
- Provider-Metadaten wie Usage oder Rate-Limits als Rohdaten mitführen

---

## Unterstützte Provider / APIs

### DeepL
- Branchenprimus mit sehr guter Qualität bei gängigen Sprachen
- `deepl-api-free-v2`
- `deepl-api-pro-v2`
- Unterstützt `context` und `customInstructions`

### Amazon Translate
- Gute bis sehr gute Übersetzungsqualität
- `amazon-translate-v1`
- API-Key-/Credential-basiert je nach Provider-Implementierung

### Google Translate Basic v2
- Gute bis sehr gute Übersetzungsqualität
- `google-translate-basic-v2`
- API-Key-basiert
- Keine Prompt-Optionen

### Google Translate v3
- Sehr gute Übersetzungsqualität
- Service-Account / OAuth-basiert
- Keine Prompt-Optionen

### LibreTranslate
- Gute Qualität - ausreichend für die meisten Zwecke
- Open-Source - kann auch selbst gehostet werden 
- `libretranslate-v1`
- Optional `apiKey`
- Keine Prompt-Optionen

### MyMemory
- Einfache, eher technische Übersetzung
- `mymemory-v2`
- Endpoint-basiert (Standard: `https://api.mymemory.translated.net/get`)
- Optional `apiKey` und `email`
- Keine Prompt-Optionen

### OpenAI-kompatible LLMs
- Je nach Modell - flexibel einsetzbar.
- `openai`
- Frei konfigurierbare Endpunkte und Parameter
- Unterstützt `context` und `customInstructions`

### Fake Local
- praktisch während der Entwicklung
- Erzeugt einfaches "Kauderwelsch" um die Funktion zu testen
- nur lokal - keine API - keine Kosten

---

## Kosten
Die Kosten der jeweiligen Provider sind sehr unterscheidlich und setzen sich meistens aus einer monatlichen Grundgebühr (Abo) und Kosten je 1 Mio Zeichen zusammen. Oft gibt es auch kostenlose oder inkludierte Kontingente. Das muss jeder selbst vergleichen. LibreTranslate kann auf entsprechender Hardware auch selbst gehostet werden. Für eine umfangreiche Webseite muss man je Sprache mit 20–50 EUR rechnen (natürlich nur ganz grob)

## Konfiguration

Die Konfiguration erfolgt über die Backend-Seite `Connections`. Dort werden Verbindungen definiert. Je nach Schnittstelle werden entsprechende Angaben gespeichert: 

- Key (Identifizierung)
- Label (Bezeichnung)
- Provider / API (Anbieter / Schnittstelle)
- Debug-Flag
- Timeout
- Max. Zeichen
- Playground-Flag (im Playground verfügbar)
- verschiedene providerspezifische Parameter

Hinweise:
- Die Standard-Connection wird automatisch verwendet, wenn bei der Abfrage keine individuelle `Connection` definiert ist.
- Die Standard-Connection und auch die Verfügbarkeit im Playground kann in der Connections-Übersicht schnell umgeschaltet werden

---

## Playground

Hier lassen sich Abrufe manuell testen.

### Eingaben
- Verbindung
- Quell- und Zielsprache
- Format (`text` oder `html`)
- Text
- Optionaler Key
- Je nach Provider zusätzlich `context` und `customInstructions`

### Key-Verhalten

Wenn ein `key` gesetzt ist, arbeitet vTrans modell- und zielsprachebezogen mit einem stabilen Datensatz.

- Ist bereits ein Eintrag mit identischem Hash vorhanden, wird dieser wiederverwendet.
- Hat sich der Inhalt geändert, wird der bestehende Key-Datensatz aktualisiert.
- Ohne Key greift nur der normale Cache über Hash, Verbindung, Sprache und Format.

## Verwendung im Code

vTrans verwendet den Namespace `FriendsOfRedaxo\VTrans`.

```php
use FriendsOfRedaxo\VTrans\VTrans;           // Namespace für die VTrans-Klasse

$translated = VTrans::translate(
    '<p>Hello world</p>',                    // zu übersetzender Content
    'en',                                    // Quellsprache (auch 'auto' möglich)
    'de',                                    // Zielsprache
    'html',                                  // Format (text oder html)
    'homepage_hero',                         // Optionaler Key
    [                                        // weitere optionale Parameter
        'connection' => 'deepl_free',        // Connection (sonst wird Standard-Connection verwendet)
        'context' => 'Marketing headline',   // zusätzliche Kontext (falls unterstützt)
        'customInstructions' => [            // zusätzliche Vorgaben (falls unterstützt)
            'Use formal tone.',
            'Keep HTML tags unchanged.',
        ],
    ]
);

echo $translated;

// Optionale Debug-Ausgaben
$meta = VTrans::getLastResultMeta();
$data = VTrans::getLastResultData();
dump($meta);
dump($data);
```

Unterstützte Request-Optionen sind unter anderem:

- `connection`: Key einer gespeicherten Verbindung (empfohlen)
- `context`: zusätzlicher Kontext für unterstützte Provider
- `customInstructions`: Array oder mehrzeiliger String mit zusätzlichen Vorgaben
- `debug`: aktiviert Debug-Daten des Providers
- `cache`: boolean (`true` als Standard). Mit `false` wird DB-Cache-Lookup und Persistierung übersprungen.

## Einfaches Template Beispiel

Hier wird einfach der deutsche Originalinhalt übersetzt und in einer anderen Sprache ausgegeben, wenn für diese Sprache kein eigener Inhalt verfügbar ist - der Artikel also leer ist.

```php
<?php
use FriendsOfRedaxo\VTrans\VTrans;

// Aktuelle Sprache des REDAXO-Artikelkontexts
$curLang = rex_clang::getCurrent()->getCode();

// Inhalt des aktuellen Artikels
$articleContent = $this->getArticle();

// Wenn wir uns in einer nicht-Standardsprache befinden und noch kein Inhalt vorhanden ist,
// holen wir den deutschen Originalinhalt und lassen ihn von vTrans übersetzen.
if (rex_clang::getCurrentId() !== 1 && $articleContent === '') {
    // Deutscher Originalinhalt aus der Basis-Sprache (ID 1)
    $articleContentOrg = (new rex_article_content(rex_article::getCurrentId(), 1))->getArticle();

    $articleContent = VTrans::translate(
        $articleContentOrg,                         // Zu übersetzender Inhalt
        'de',                                       // Quellsprache
        $curLang,                                   // Zielsprache
        'html',                                     // Format
        'artCont-' . rex_article::getCurrentId()    // Key für besseres Caching
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
    <!-- Einfacher Sprachumschalter für die Demo -->
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

In diesem Beispiel wird nur der eigentliche Inhalt übersetzt. Andere Teile wie z.B. die Navigation, Footer, Meta-Daten etc. können auf die gleiche Weise übersetzt werden. Bei der Navigation empfiehlt sich oft eine manuelle Übersetzung, weil darüber meistens auch die URL beeinflusst wird.

### No-Cache-Modus

Mit `cache => false` lässt sich ein Abruf direkt über die API ausführen, ohne vorher in der Datenbank nachzusehen und ohne das Ergebnis anschließend zu speichern.

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

Im `no-cache`-Modus gilt:

- kein Datenbank-Lookup vor dem Provider-Call
- kein Speichern des Ergebnisses in `rex_vtrans`
- Rohdaten des Providers stehen trotzdem direkt über `VTrans::getLastResultData()` zur Verfügung

`VTrans::getLastResultData()` liefert die Rohdaten des letzten Requests, z. B. Usage-Daten, Rate-Limits, Debug-Informationen oder provider-spezifische Metadaten.

---

## HTML-Filter (Inhalte von der Verarbeitung ausschließen)

Beim Format `html` läuft automatisch ein provider-unabhängiger HTML-Filter, der bestimmte Inhalte vor dem API-Call schützt und nach der Übersetzung wiederherstellt.

### Inhalte nicht übersetzen (`translate="no"`, `.notranslate`)

```html
<span translate="no">Thomas König</span>
<span class="notranslate">REDAXO CMS</span>
```

### Blöcke komplett ausschließen (`data-vtrans-exclude`)

```html
<div data-vtrans-exclude>
    <script>var config = { lang: 'de' };</script>
    <p>Dieser Block wird nicht an die API gesendet.</p>
</div>
```

### Automatisch ausgeschlossene Tags

- `<script>…</script>`
- `<style>…</style>`
- `<code>…</code>`
- `<svg>…</svg>`

---

## Support

- Projekt: https://github.com/FriendsOfREDAXO/vtrans
- Community: https://www.redaxo.org

## Credits

- Friends Of REDAXO
- [Matthias Weiss / VIEWSION.net](https://github.com/VIEWSION) (Lead)

---

## Lizenz

MIT, siehe `LICENSE`.
