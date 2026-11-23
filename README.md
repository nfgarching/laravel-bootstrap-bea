# laravel-bootstrap-bea

Import heruntergeladener **beA-Nachrichten** (ZIP) in eine Laravel-Anwendung –
mit Auswertung des XJustiz-Strukturdatensatzes, Prüfung des
Vertrauenswürdigen Herkunftsnachweises (VHN), nachvollziehbarer Prüfhistorie
und einer Übernahme von Hand: Auswahl der Dateien mit Vorschlägen, Zuordnung
zu Akte und Namen.

Baut auf [`nfgarching/files`](https://github.com/nfgarching/laravel-bootstrap-files)
auf: Die von Hand ausgewählten Dateien werden dort abgelegt und durchlaufen
OCR und PDF-Prüfung; das empfangene Original bleibt dabei unverändert.

---

## Ablauf

Der Import läuft in zwei Schritten. Die ZIP ist dabei immer die Quelle – sie
liegt unverändert bei den Originalen (siehe [Ablage](#ablage)).

```
1. Einlesen (automatisch oder von Hand)
   ZIP ──► sicher entpacken ──► je Nachricht auslesen ──► schon bekannt? ──► überspringen
                                        │
                                        ▼
      VHN prüfen (Prüfsummen + Signatur) ──► ZIP zu den Originalen ({Jahr}/beas/{Jahr}-{Monat})
                                        │
                                        ▼
      alle Dateien mit SHA-256 und Vorschlag erfassen ──► Event BeaMessageImported

2. Übernehmen (immer von Hand)
   Auswahl mit Vorschlägen + Zuordnung zu Akte und Namen
                                        │
                                        ▼
      ZIP und gewählte Dateien gegen die erfassten Prüfsummen prüfen
                                        │
                                        ▼
      Dateien an nfgarching/files ──► Event BeaFilesAccepted (Files bleiben bei der Nachricht)
```

- **Sammel-ZIPs** aus dem Export mehrerer Nachrichten (z. B. `Posteingang.zip`
  mit je einer `Nachricht_….zip`) werden aufgelöst: jede innere ZIP wird als
  eigene Nachricht eingelesen und als eigenes Original abgelegt (eine Ebene
  tief). Eine fehlerhafte innere ZIP erscheint als Hinweis, die übrigen
  werden trotzdem eingelesen. Ordner mit mehreren Nachrichten in einer ZIP
  gehen ebenfalls.
- **Nicht alles wird übernommen.** Vorgeschlagen werden Verfügungen,
  Schriftsätze und Schreiben; Anlagen stehen zur Wahl, sind aber nicht
  vorausgewählt. Die Regeln stehen in `config/bea.php` (`suggest`).
- **Signaturdateien (`.p7s`) und technische Dateien** (XJustiz, VHN,
  Exportansicht, alle `technisches_pruefprotokoll*`) bleiben in der ZIP,
  ebenso Dateien mit den Endungen aus `bea.exclude_extensions` (Standard
  `xml`, env `BEA_EXCLUDE_EXTENSIONS`). Sie sind mit Prüfsumme erfasst und auf
  der Detailseite sichtbar, werden aber nie übernommen.
- **Signierte Dateien** – solche mit abgesetzter Signatur in der ZIP – werden
  im Original übernommen, als „Original Dokument“ gekennzeichnet und im
  Files-Package gesperrt (`isLocked()`): kein Ersetzen durch die
  Archivfassung, kein Löschen. Der OCR-Text entsteht trotzdem als eigene
  Textdatei (`$file->ocrText()`, Event `FileTextExtracted`), z. B. für eine
  Volltextsuche.
- Ein erneutes Einlesen derselben Nachricht (beA-ID bzw. OSCI-ID) wird übersprungen.

## Installation

```bash
git clone git@github.com:nfgarching/laravel-bootstrap-bea.git packages/nfgarching/bea
```

`composer.json` der Anwendung:

```json
"repositories": [
    { "type": "path", "url": "packages/nfgarching/bea" }
],
"require": {
    "nfgarching/bea": "@dev"
}
```

```bash
composer update nfgarching/bea
php artisan migrate
php artisan vendor:publish --tag=bea-config   # für die Zuordnungstypen nötig
```

## Ablage

Die ZIPs liegen an drei Stellen, jede mit eigener Disk und eigenem Pfad:

| Ablage | Disk | Pfad (Standard) | Inhalt |
|---|---|---|---|
| Eingang | `BEA_INBOX_DISK` (= `BEA_DISK`) | `beas/inbox/` | heruntergeladene ZIPs – wie `scans` im Files-Package |
| Originale | `BEA_DISK` (`local`) | `{Jahr}/beas/{Jahr}-{Monat}/` | jede eingelesene ZIP unverändert (Beweisstück, mit SHA-256) |
| failed | `BEA_FAILED_DISK` (= `BEA_DISK`) | `beas/failed/` | ZIPs, die sich nicht einlesen ließen |

Standard-Disk ist `local` (`storage/app/private`). Fehlt die Disk `BEA_DISK`
in `config/filesystems.php`, legt das Package sie selbst lokal an
(`BEA_DISK_ROOT`, sonst `storage/app/beas`) – bewusst ohne URL und ohne
öffentlichen Link. Eingang und failed müssen auf vorhandenen Disks liegen.

### Ordner der Originale

Aufgebaut wie im `PathGenerator` des Files-Packages (`FILE_DIR_PREFIX`/`_SUFFIX`/`_POSTFIX`):

```text
[{BEA_DIR_PREFIX}/][{Jahr}/][{BEA_DIR_SUFFIX}/]{BEA_PATH}/{Jahr}-{Monat}[/{BEA_DIR_POSTFIX}]
```

Jahr und Monat sind die des Einlesens. Die Jahresebene vor `BEA_PATH` gibt es
immer; `BEA_DIR_YEAR=false` schaltet sie ab.

| Einstellung | Ordner |
|---|---|
| Standard | `local: 2026/beas/2026-11/` |
| `BEA_DISK=data`, `BEA_DIR_SUFFIX=akv` | `data: 2026/akv/beas/2026-11/` |
| `BEA_DIR_YEAR=false` | `local: beas/2026-11/` |

Dateiname: die ersten 12 Zeichen der SHA-256 und der Originalname, z. B.
`0934ce8c595a_Nachricht_656956044.zip`. Eine inhaltsgleiche ZIP wird nicht
doppelt abgelegt.

Disk und Pfad stehen je Nachricht in `bea_messages.archive_disk` und
`archive_path`. Eine geänderte Einstellung gilt daher nur für neu eingelesene
ZIPs; vorhandene Originale bleiben, wo sie sind, und werden dort gefunden.

### Beispiel: Eingang auf einem Scan-Share

```dotenv
BEA_DISK=data
BEA_PATH=beas
BEA_DIR_SUFFIX=akv
BEA_INBOX_DISK=scans
BEA_INBOX_PATH=beA_Import
BEA_FAILED_DISK=scans
BEA_FAILED_PATH=beA_Import/failed
```

Originale unter `data: 2026/akv/beas/2026-11/`, Eingang und failed unter
`scans: beA_Import/`. Liegt der Ordner der Originale unterhalb des Eingangs,
wird er nicht als Eingang behandelt: Eingelesen werden nur ZIPs direkt im
Eingangsordner.

## Einlesen

- **Modal in der Liste:** `/bea` – die Knöpfe „Eingang“ und „Hochladen“
  öffnen ein Modal mit beiden Wegen (`<livewire:bea.import :modal="true" />`,
  `<livewire:bea.upload :modal="true" />`). Es wird nur eingelesen, das
  Ergebnis bietet kein „Übernehmen“ an; beim Schließen lädt die Liste neu,
  wenn etwas eingelesen wurde (Browser-Event `bea-ingested`).
- **Eingang im Browser:** `/bea/import` – ZIPs aus dem Eingang (`BEA_INBOX_DISK`/`BEA_INBOX_PATH`) auflisten,
  markieren, einlesen oder löschen.
- **Upload:** `/bea/upload`.
- **Befehl / Scheduler:**

  ```bash
  php artisan bea:import            # alle ZIPs im Eingang
  php artisan bea:import a.zip b.zip
  php artisan bea:import --force    # bereits eingelesene erneut einlesen
  php artisan bea:import --keep     # ZIPs im Eingang belassen
  ```

  Mit `BEA_IMPORT_SCHEDULE="*/10 * * * *"` läuft das Einlesen über den
  Laravel-Scheduler. Der Befehl liest **nur ein** – übernommen wird nie
  automatisch.

## Übernehmen

`/bea/{id}/select`: Dateien wählen (vorbelegt mit den Vorschlägen), Akte und
Namen zuordnen, Betreff für die Übergabe festlegen. Nicht gewählte Dateien
lassen sich später nachholen.

### Mehrere Nachrichten auf einmal (Popup/iframe-Modal)

`<livewire:bea.pending />` listet alle eingelesenen, noch nicht übernommenen
Nachrichten. Markierte Nachrichten werden in einem Schritt mit ihren
**vorgeschlagenen** Dateien übernommen, wie der Scan-Import des Files-Packages.

- Route `bea.pending` (`?model_type=…&model_id=…&data=…`, `data` als JSON).
- Mit `<x-button.dispatch frame frameSize="xl" :href="route('bea.pending')" …>`
  (core) öffnet ein Klick die Seite im generischen iframe-Modal, **SHIFT** als
  Popup, CTRL in einem Tab.
- Standard ist `model_type` = `bea.default_model_type` (`App\Models\User`)
  ohne ID: die Zuordnung bleibt beim User. Ein aufrufendes Modell (z. B. ein
  Dokument) wird die Zuordnung der Nachricht, sofern es in `bea.assign.types`
  steht; Schlüssel aus `data` gibt `bea.assign.context` als Kontext an das
  Event `BeaFilesAccepted` (`'Register_ID' => ['type' => 'akte', 'column' => 'ID']`).
- Bei genau einer Nachricht zeigt `finishPopup()` danach das Modell aus
  `BeaFilesAccepted::$route` an (z. B. das neue Dokument; aus einem Modell
  heraus im aufrufenden Fenster, sonst in einem neuen Fenster). Bei mehreren
  lädt `reloadOpener()` (Layout `layout::popup`) das Hauptfenster (Modal)
  bzw. das aufrufende Fenster (Popup) neu; ohne Opener geht es auf `/bea`.
- **Vorschau:** Der Pfeil vor einer Nachricht klappt ihre Anlagen auf und
  zeigt gleich die erste vorgeschlagene PDF im Modal (Klick: Vorschau,
  SHIFT: Popup, CTRL: Tab). Die Datei kommt direkt aus der Original-ZIP über
  `bea.file` (`/bea/{message}/file/{n}`, `n` = `BeaFile::$id`), nur Anlagen, nur wenn
  die SHA-256 noch zum Einlesen passt (sonst 409).

### Einzelne Nachricht übernehmen (`bea.select`)

`/bea/{message}/select` ist eine Popup-Seite (`layout::popup`) wie `bea.pending`:
Detailseite, Liste, `bea.listing` und das Ergebnis des Einlesens öffnen sie mit
`<x-button.dispatch frame frameSize="fullscreen">` im iframe-Modal (Klick: Modal,
SHIFT: Popup, CTRL: Tab). Die Anlagen sind mit den Vorschlägen vorbelegt; der
Knopf je Anlage (`bea::parts.file-link`, wie `x-storage.link` aus files) zeigt
sie aus der Original-ZIP über `bea.file` in einem gemeinsamen Fullscreen-Modal
(`bea::parts.file-modal`, lädt die Datei erst beim Öffnen; SHIFT: Popup,
CTRL: Tab). Modelle der Anwendung (Akte, Namen …) werden dort **nicht**
ausgewählt; Query wie bei `bea.pending`: `model_type`/`model_id` als Ziel,
`data` (Schlüssel aus `bea.assign.context`) als Kontext. Nach dem Übernehmen
zeigt `finishPopup()` das Modell aus `BeaFilesAccepted::$route` an (wie
`files.import`: aus einem Modell heraus im aufrufenden Fenster, sonst in einem
neuen Fenster); ohne Route lädt `reloadOpener()` Hauptfenster bzw. Opener neu.

Anzeige und Auflösen der Zuordnung brauchen die Modelle der Anwendung in `config/bea.php`:

```php
'assign' => [
    'types' => [
        'akte' => [
            'label' => 'Akte',
            'model' => App\Models\Akte::class,
            'search' => ['aktenzeichen', 'bezeichnung'],
            'display' => 'aktenzeichen',
            'match' => 'aktenzeichen',   // Vorschlag anhand des Aktenzeichens der Nachricht
        ],
        'name' => [
            'label' => 'Name',
            'model' => App\Models\Names\Name::class,
            'search' => ['name', 'vorname'],
            'display' => 'name',
        ],
    ],
],
```

Jede Nachricht hat **eine Zuordnung** (`bea_messages.model`, nullable): beim
Einlesen den User, der einliest (bei `bea:import` auf der Konsole keine), bzw.
das Modell, aus dem heraus eingelesen wird; nach der Übernahme das Modell,
das die Anwendung im Listener auf `BeaFilesAccepted` anlegt und mit
`$message->assignTo($dokument)` einträgt. Die übernommenen Files gehören
immer der beA-Nachricht (`model_type` = `BeaMessage`, empfangener Stand als
Beweis) und werden nicht umgehängt; das zugeordnete Modell erreicht sie über
`bea_messages.model` (z. B. als zusätzlicher Besitzer in `files.listing`).
Ein `NewFiles`-Event löst die Übernahme nicht aus.

Kontext-Modelle aus dem Aufruf (Akte, Name …) werden nicht gespeichert:
sie gehen als `context` mit dem Event `BeaFilesAccepted` an die Anwendung,
die fehlende Zuordnungen (z. B. Akte über das Aktenzeichen) selbst ermittelt.

## Empfangene und gesendete Nachrichten

Auch Exporte aus dem Ordner „Gesendet“ lassen sich einlesen. Die Richtung
(`direction`: `in`/`out`) erkennt das Package am Journal der Exportansicht
(„Zeitpunkt des erfolgreichen Versands (bei versandten Nachrichten)“), sonst
an `BEA_OWN_SAFE_IDS`.

- `dated_at` ist das maßgebliche Datum: Eingang im Postfach, bei gesendeten
  Nachrichten der Versand (sie haben kein „Eingang Postfach“). Listen
  sortieren und filtern (Jahr) danach; Filter „Richtung“ in der Liste.
- `aktenzeichen` ist immer das **eigene** Aktenzeichen, `aktenzeichen_absender`
  das der Gegenseite – bei gesendeten Nachrichten also getauscht gegenüber
  der Exportansicht (die Rohwerte bleiben in `data.export_fields`). So greifen
  Vorschlag und Aktensuche anhand des Aktenzeichens für beide Richtungen.
- Gesendete Nachrichten tragen in Listen, Modal und Detailseite ein Badge
  „gesendet“.

## Anzeigen

- `/bea` – Liste mit Suche und Filtern (Status, Zuordnung, Prüfung, Jahr).
- `/bea/{id}` – Detailseite mit den Tabs **Nachricht** (Kopfdaten, Verfahren,
  Beteiligte), **Dateien** (jede Datei der ZIP mit SHA-256, VHN-Abdeckung und
  Übernahmestatus), **Prüfung** und **Journal**.

### In Akten, Dokumenten und Namen

```blade
<livewire:bea.listing :model_type="$akte->getMorphClass()" :model_id="$akte->id" />

{{-- mehrere Besitzer, z. B. Akte und ihre Dokumente --}}
<livewire:bea.listing :owners="[[$akte->getMorphClass(), $akte->id], [$dokument->getMorphClass(), $dokument->id]]" />
```

Gefunden werden Nachrichten, die dem Modell zugeordnet sind, und solche, aus
denen eine Datei des Modells stammt. Die Knöpfe „Eingang“ und „Hochladen“
geben das Modell mit: die eingelesene Nachricht wird ihm zugeordnet.

## Events

| Event | Wann | Inhalt |
|---|---|---|
| `BeaMessageImported` | nach dem Einlesen | `message`, `aktenzeichen`, `aktenzeichenAbsender`, `data` – noch **keine** Dateien |
| `BeaFilesAccepted` | bei jeder Übernahme | `message` (mit `model`), `fileIds`, `context` (Akte, Namen … aus dem Aufruf als `[type, id]`), `subject`; der Listener setzt `route` (Anzeige des angelegten Modells, wie `NewFiles`) – `bea.select` öffnet sie per `finishPopup()` |

Übernahme im eigenen Listener, z. B. als Dokument:

```php
use Nfgarching\Bea\Events\BeaFilesAccepted;

class AcceptBeaMessage
{
    public function handle(BeaFilesAccepted $event): void
    {
        $message = $event->message;
        $dokument = $message->model instanceof Dokument
            ? $message->model                       // Nachholen: dasselbe Dokument
            : Dokument::create([/* Akte aus $event->context … */]);

        // Files bleiben bei der Nachricht; das Dokument findet sie über die Zuordnung
        $message->assignTo($dokument);
    }
}
```

Mit `'match'` in den Zuordnungstypen (siehe oben) liefert
`AssignTypes::suggestionsFor()` die Akte anhand des Aktenzeichens.

### Models

- `Data\BeaZip` (keine Tabelle) – Original-ZIP aus den Spalten `archive_disk`, `archive_path`,
  `archive_name`, `archive_sha256`, `archive_size`, `archive_source`; `$message->archive`.
  Mehrere Nachrichten einer ZIP verweisen auf dieselbe Datei.
- `BeaMessage` – `status` (`ingested`/`accepted`), Kopfdaten, `integrity`,
  `vhn_signature`, `data`, Zuordnung `model` (MorphTo); `archive`,
  `verifications`, `files()` (übernommene Files, MorphMany), `messageFiles()`
  (alle Dateien der ZIP aus `data.files`), `attachmentFiles()`,
  `suggestedFiles()`, `attachments_count`, `assignTo($model)`, `unassign()`,
  `isAssigned()` (einem Modell zugeordnet, nicht nur dem User),
  `acceptedFiles()`, Scopes `assigned()`, `forOwners()` und `search()`.
- `Data\BeaFile` (keine Tabelle) – eine Datei der ZIP aus `data.files`:
  `id` (laufende Nummer, Schlüssel für Auswahl und Vorschau), `role`
  (`attachment`/`signature`/`meta`), `zip_path`, `category`, `suggested`,
  `signed`, `signature_name`, `sha256`, `vhn_covered`; übernommen, wenn die
  Nachricht ein File mit diesem `custom_properties.bea.zip_path` hat
  (`file`, `fileId()`, `acceptedAt()`).
- `BeaVerification`.

## Prüfungen und Nachvollziehbarkeit

Jede Prüfung wird in `bea_verifications` festgehalten – beim Einlesen, vor
jeder Übernahme und auf Knopfdruck („Erneut prüfen“ im Tab **Prüfung**, bzw.
`BeaIntegrityChecker::check($message)`):

| Prüfung | Bedeutung |
|---|---|
| ZIP unverändert | SHA-256 der ZIP auf der Disk gegen den Wert vom Einlesen |
| Dateien unverändert | SHA-256 jeder Datei in der ZIP gegen die erfasste Prüfsumme |
| `integrity` | `complete`: jede Datei hat eine passende SHA-256-Prüfsumme im VHN und umgekehrt · `incomplete`: Abweichung · `missing`: kein VHN |
| `vhn_signature` | `valid`: Signatur gültig, Kette bis zu den hinterlegten BNotK-Zertifikaten · `signature_ok`: mathematisch gültig, Kette nicht geprüft · `invalid` · `missing`, `notool`, `disabled` |
| `secure_channel` | „Sicherer Übermittlungsweg“ laut VHN |

Eine Übernahme wird abgebrochen, wenn die ZIP oder eine gewählte Datei nicht
mehr dem Stand beim Einlesen entspricht.

Für `valid` liegt das Zwischenzertifikat „beA VHN CA 2017“ der BNotK (Aussteller
der VHN-Signaturzertifikate, gültig bis 2037) unter
`resources/certs/2021-09-09-bea-vhn-ca-2017.pem` und ist Standard für
`bea.vhn.trust`. Es dient per `openssl -partial_chain` selbst als Vertrauensanker,
die Wurzel „SAFE Root CA 2017“ wird nicht benötigt. Eine andere PEM-Datei über
`BEA_VHN_TRUST` (absolut oder relativ zum Projekt); `BEA_VHN_TRUST=` (leer)
schaltet die Kettenprüfung ab.
Signaturen einzelner Anhänge (`*.p7s`) werden nicht selbst geprüft; die Datei
bleibt dafür samt Signatur im Original erhalten.

## Konfiguration (`.env`)

| Variable | Standard | Bedeutung |
|---|---|---|
| `BEA_DISK` | `local` | Disk der Originale; Vorgabe für Eingang und failed (siehe [Ablage](#ablage)) |
| `BEA_DISK_ROOT` | `storage/app/beas` | Wurzel, nur wenn das Package die Disk `BEA_DISK` selbst anlegt |
| `BEA_PATH` | `beas` | Ordner der Originale |
| `BEA_DIR_YEAR` | `true` | Jahresebene vor `BEA_PATH`; `false` = ohne |
| `BEA_DIR_PREFIX` / `BEA_DIR_SUFFIX` / `BEA_DIR_POSTFIX` | – | weitere Teile des Ordners der Originale |
| `BEA_INBOX_DISK` / `BEA_INBOX_PATH` | – (= `BEA_DISK`) / `beas/inbox` | Eingang |
| `BEA_FAILED_DISK` / `BEA_FAILED_PATH` | – (= `BEA_DISK`) / `beas/failed` | nicht einlesbare ZIPs |
| `BEA_IMPORT_SCHEDULE` | – | Cron-Ausdruck für `bea:import` |
| `BEA_FILES_COLLECTION` | `bea` | `collection_name` der übernommenen Dateien |
| `BEA_VHN_VERIFY` | `true` | VHN-Signatur mit OpenSSL prüfen |
| `BEA_VHN_TRUST` | `resources/certs/2021-09-09-bea-vhn-ca-2017.pem` (Package) | PEM-Datei der BNotK-Zertifikate; leer = nur mathematische Prüfung |
| `BEA_OWN_SAFE_IDS` | – | eigene SAFE-IDs (kommagetrennt); erkennt gesendete Nachrichten, falls der Export kein Journal hat |
| `BEA_UPLOAD_MAX_KB` | `204800` | maximale ZIP-Größe beim Upload |

## Datenschutz

Echte beA-Nachrichten gehören **nicht** ins Repository (`*.zip` ist
ausgeschlossen). Die Tests verwenden eine frei erfundene Nachricht
(`tests/Fixtures`, erzeugt mit `tests/Fixtures/generate.sh`).

## Tests

```bash
php artisan test packages/nfgarching/bea/tests
```
