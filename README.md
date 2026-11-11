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
liegt unverändert auf der Disk `beas`.

```
1. Einlesen (automatisch oder von Hand)
   ZIP ──► sicher entpacken ──► je Nachricht auslesen ──► schon bekannt? ──► überspringen
                                        │
                                        ▼
      VHN prüfen (Prüfsummen + Signatur) ──► ZIP nach beas/archive/{Jahr}
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
      Dateien an nfgarching/files ──► Events NewFiles (Dokumente) und BeaFilesAccepted
```

- **Nicht alles wird übernommen.** Vorgeschlagen werden Verfügungen,
  Schriftsätze und Schreiben; Anlagen stehen zur Wahl, sind aber nicht
  vorausgewählt. Die Regeln stehen in `config/bea.php` (`suggest`).
- **Signaturdateien (`.p7s`) und technische Dateien** (XJustiz, VHN,
  Exportansicht, Prüfprotokolle) bleiben in der ZIP. Sie sind mit Prüfsumme
  erfasst und auf der Detailseite sichtbar.
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

## Disk `beas`

Das Package legt die Disk selbst an (lokal unter `storage/app/beas`), sofern
`config/filesystems.php` keine Disk dieses Namens definiert. Sie hat bewusst
keine URL und keinen öffentlichen Link.

| Ordner | Inhalt |
|---|---|
| `inbox/` | Eingang: heruntergeladene ZIPs – wie `scans` im Files-Package |
| `archive/{Jahr}/` | jede eingelesene ZIP im Original (`bea_archives`, mit SHA-256) |
| `failed/` | ZIPs, die sich nicht einlesen ließen |

## Einlesen

- **Eingang im Browser:** `/bea/import` – ZIPs aus `beas/inbox` auflisten,
  markieren, einlesen oder löschen.
- **Upload:** `/bea/upload`.
- **Befehl / Scheduler:**

  ```bash
  php artisan bea:import            # Eingang der Disk
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

Die Zuordnung braucht die Modelle der Anwendung in `config/bea.php`:

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

Die **erste Zuordnung** einer Nachricht ist die Hauptzuordnung: An sie gehen
die übernommenen Dateien per `NewFiles`-Event des Files-Packages (wie bei
Upload und Scan-Import). Ohne Zuordnung bleiben die Dateien bei der
beA-Nachricht und werden mit der ersten Zuordnung nachgereicht.

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
geben das Modell mit, sodass die Zuordnung vorbelegt ist.

## Events

| Event | Wann | Inhalt |
|---|---|---|
| `BeaMessageImported` | nach dem Einlesen | `message`, `aktenzeichen`, `aktenzeichenAbsender`, `data` – noch **keine** Dateien |
| `Nfgarching\Files\Events\NewFiles` | bei der Übernahme, wenn eine Hauptzuordnung besteht | `fileIds`, `model_type`, `model_id`, `subject`, `data` (`source: bea`, beA-ID, Aktenzeichen, alle Zuordnungen) |
| `BeaFilesAccepted` | bei jeder Übernahme | `message`, `fileIds`, `assignments` (alle, Hauptzuordnung zuerst) |

Vorab-Zuordnung im eigenen Listener (erscheint bei der Übernahme als Zuordnung):

```php
use Nfgarching\Bea\Events\BeaMessageImported;

class AssignBeaMessage
{
    public function handle(BeaMessageImported $event): void
    {
        // Das Aktenzeichen ist Freitext des Absenders – ggf. normalisieren
        preg_match('#\d{2}/\d{3,5}#', (string) $event->aktenzeichen, $match);
        $akte = Akte::where('aktenzeichen', $match[0] ?? $event->aktenzeichen)->first();

        $akte && $event->message->assignTo($akte);
    }
}
```

Mit `'match'` in den Zuordnungstypen (siehe oben) schlägt das Package die
Akte auch ohne eigenen Listener vor.

### Models

- `BeaArchive` – ZIP auf der Disk: `disk`, `path`, `original_name`, `sha256`, `size`, `source`.
- `BeaMessage` – `status` (`ingested`/`accepted`), Kopfdaten, `integrity`,
  `vhn_signature`, `data`; `archive`, `messageFiles`, `assignments`,
  `verifications`, `assignTo($model)`, `primaryAssignment()`, `acceptedFiles()`,
  Scopes `forOwners()` und `search()`.
- `BeaMessageFile` – jede Datei der ZIP: `role` (`attachment`/`signature`/`meta`),
  `zip_path`, `category`, `suggested`, `signed`, `signature_name`, `sha256`,
  `vhn_covered`, `file_id` und `accepted_at` nach der Übernahme.
- `BeaAssignment`, `BeaVerification`.

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

Für `valid` die Wurzel- und Zwischenzertifikate der BNotK-Zertifizierungsstelle
als PEM-Datei ablegen und `BEA_VHN_TRUST=/pfad/zu/bnotk-ca.pem` setzen.
Signaturen einzelner Anhänge (`*.p7s`) werden nicht selbst geprüft; die Datei
bleibt dafür samt Signatur im Original erhalten.

## Konfiguration (`.env`)

| Variable | Standard | Bedeutung |
|---|---|---|
| `BEA_DISK` / `BEA_DISK_ROOT` | `beas` / `storage/app/beas` | Disk für Eingang und Originale |
| `BEA_INBOX_PATH` | `inbox` | Eingang auf der Disk |
| `BEA_ARCHIVE_PATH` / `BEA_FAILED_PATH` | `archive` / `failed` | Originale bzw. nicht einlesbare ZIPs |
| `BEA_IMPORT_SCHEDULE` | – | Cron-Ausdruck für `bea:import` |
| `BEA_FILES_COLLECTION` | `bea` | `collection_name` der übernommenen Dateien |
| `BEA_VHN_VERIFY` | `true` | VHN-Signatur mit OpenSSL prüfen |
| `BEA_VHN_TRUST` | – | PEM-Datei der BNotK-Zertifikate |
| `BEA_UPLOAD_MAX_KB` | `204800` | maximale ZIP-Größe beim Upload |

## Datenschutz

Echte beA-Nachrichten gehören **nicht** ins Repository (`*.zip` ist
ausgeschlossen). Die Tests verwenden eine frei erfundene Nachricht
(`tests/Fixtures`, erzeugt mit `tests/Fixtures/generate.sh`).

## Tests

```bash
php artisan test packages/nfgarching/bea/tests
```
