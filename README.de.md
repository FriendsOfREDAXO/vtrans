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

Über den REDAXO-Installer installieren oder manuell nach `redaxo/src/addons/vtrans` kopieren und anschließend im Backend aktivieren.

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
- Der Standard-Connector wird automatisch verwendet, wenn beim Aufruf kein `connection`-Wert übergeben wird.

---

## Funktionen

- Mehrere Provider über eine einheitliche API ansprechen
- Verbindungen zentral im Backend verwalten
- Abrufe manuell im Backend testen (Playground)
- DB Cache über Hash, Verbindung, Sprache und Format nutzen
- Stabile Keys für wiederverwendbare Inhalte unterstützen
- Gespeicherte Datensätze unter `Daten` suchen, filtern, prüfen und bearbeiten
- Provider-Metadaten wie Usage oder Rate-Limits als Rohdaten mitführen

---

## Unterstützte Provider / APIs

### DeepL
- `deepl-api-free-v2`
- `deepl-api-pro-v2`
- Unterstützt `context` und `customInstructions`

### Amazon Translate
- `amazon-translate-v1`
- API-Key-/Credential-basiert je nach Provider-Implementierung

### Google Translate Basic v2
- `google-translate-basic-v2`
- API-Key-basiert
- Keine Prompt-Optionen

### Google Translate v3
- `google-translate-v3`
- Service-Account / OAuth-basiert
- Keine Prompt-Optionen

### LibreTranslate
- `libretranslate-v1`
- Optional `apiKey`
- Keine Prompt-Optionen

### MyMemory
- `mymemory-v2`
- Endpoint-basiert (Standard: `https://api.mymemory.translated.net/get`)
- Optional `apiKey` und `email`
- Keine Prompt-Optionen

### OpenAI
- `openai`
- Frei konfigurierbare Endpunkte und Parameter
- Unterstützt `context` und `customInstructions`

---

## Konfiguration

Die Konfiguration erfolgt jetzt über die Backend-Seite `Connections`. Dort werden Verbindungen mit:

- Key
- Label
- Provider
- API-Key / API-URL
- System-Prompt
- Timeout
- Max. Zeichen
- Debug
- Playground-Flag
- Provider-spezifischen Parametern

verwaltet.

Wichtige Hinweise:
- Der Default-Connector wird automatisch verwendet, wenn kein `connection`-Wert gegeben ist.
- Für die API-Nutzung werden die Verbindungen aus der Datenbank geladen und in `VTrans::translate()` verwendet.
- Für neue Integrationen können mehrere Provider-Konfigurationen nebeneinander existieren.

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

### Ergebnisblock

Der Ergebnisblock zeigt unter anderem:
- Verbindung und Datensatz-ID
- ob ein Ergebnis aus Cache oder API kam
- Token- und Rate-Limit-Daten, falls verfügbar
- einen Link zur Detailansicht unter `Daten`

Zusätzlich liefert `VTrans::getLastResultMeta()` request-lokale Metadaten wie:
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

## Daten

Die Daten werden in `rex_vtrans` gespeichert.

Wichtige Felder sind unter anderem:
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

Cache und Wiederverwendung funktionieren über:
- Hash der Anfrage
- Verbindung / Provider
- Quelle / Ziel
- Format

Key-Datensätze sind an `key + target + connection` gebunden.

---

## Verwendung im Code

vTrans verwendet den Namespace `FriendsOfRedaxo\VTrans`.

```php
use FriendsOfRedaxo\VTrans\VTrans;

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
            'Use formal tone.',
            'Keep HTML tags unchanged.',
        ],
    ]
);

$meta = VTrans::getLastResultMeta();
$data = VTrans::getLastResultData();
```

Unterstützte Request-Optionen sind unter anderem:

- `connection`: Key einer gespeicherten Verbindung (empfohlen)
- `context`: zusätzlicher Kontext für unterstützte Provider
- `customInstructions`: Array oder mehrzeiliger String mit zusätzlichen Vorgaben
- `debug`: aktiviert Debug-Daten des Providers
- `cache`: boolean (`true` als Standard). Mit `false` wird DB-Cache-Lookup und Persistierung übersprungen.

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

## Troubleshooting

- „No active translation connections configured for vTrans.“: Es existiert keine aktive Verbindung im Backend.
- „Translation connection not found“: Der übergebene `connection`-Key ist nicht vorhanden.
- „Unsupported translation API“: Der Provider-Name einer Verbindung ist unbekannt.
- Keine Usage-Anzeige: Wird nur von Providern unterstützt, die entsprechende Endpunkte liefern.

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
