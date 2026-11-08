# laravel-bootstrap-bea

Import heruntergeladener **beA-Nachrichten** (ZIP) in eine Laravel-Anwendung –
mit Auswertung des XJustiz-Strukturdatensatzes, Prüfung des
Vertrauenswürdigen Herkunftsnachweises (VHN) und einem Event, über das die
eigene Anwendung die Nachricht anhand des **Aktenzeichens** zuordnet.

Baut auf [`nfgarching/files`](https://github.com/nfgarching/laravel-bootstrap-files)
auf: Anhänge werden dort als Dateien abgelegt und durchlaufen OCR, PDF/A,
Siegel und PDF-Prüfung.

---

## Ablauf

```
ZIP ──► sicher entpacken ──► je Nachricht auslesen ──► Doppelimport? ──► überspringen
                                                   │
                                                   ▼
         VHN prüfen (Prüfsummen + Signatur) ──► ZIP, Anhänge, Prüfprotokoll, Export als File
                                                   │
                                                   ▼
                                  Event BeaMessageImported (Aktenzeichen + alle Daten)
```

- **Anhänge** sind nie „eigene Dokumente“ im Sinne des Files-Packages – der
  empfangene Stand bleibt unverändert erhalten.
- Die **ZIP** wird als Beweisstück unverändert abgelegt (`zip_file_id`).
- Ein erneuter Import derselben Nachricht (beA-ID bzw. OSCI-ID) wird übersprungen.

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
php artisan vendor:publish --tag=bea-config   # optional
```

## Importieren

- **Browser:** `/bea` – ZIPs hochladen, Liste aller Nachrichten mit Badges für
  VHN, Prüfsummen, sicheren Übermittlungsweg, eEB und Zuordnung.
- **Eingangsordner:** ZIPs nach `storage/app/bea-inbox` (Disk/Pfad in
  `config/bea.php`) legen und

  ```bash
  php artisan bea:import            # Eingangsordner
  php artisan bea:import a.zip b.zip
  php artisan bea:import --force    # bereits importierte erneut
  ```

  Importierte ZIPs werden gelöscht, fehlerhafte nach `bea-inbox/failed/`
  verschoben. Mit `BEA_IMPORT_SCHEDULE="*/10 * * * *"` läuft der Import über
  den Laravel-Scheduler.

## Zuordnung im eigenen Projekt: Listener

```php
namespace App\Listeners;

use App\Models\Akte;
use Nfgarching\Bea\Events\BeaMessageImported;

class AssignBeaMessage
{
    public function handle(BeaMessageImported $event): void
    {
        // Das Aktenzeichen ist Freitext des Absenders – ggf. normalisieren
        preg_match('#\d{2}/\d{3,5}#', (string) $event->aktenzeichen, $match);
        $akte = Akte::where('aktenzeichen', $match[0] ?? $event->aktenzeichen)->first();

        if (! $akte) {
            return; // bleibt im beA-Eingang als „nicht zugeordnet“
        }

        $event->message->assignTo($akte);
        $event->attachments()->each->update([
            'model_type' => $akte::class,
            'model_id' => $akte->id,
        ]);
    }
}
```

Laravel findet Listener in `app/Listeners` automatisch.

### Inhalt des Events

| Eigenschaft | Inhalt |
|---|---|
| `$event->message` | `BeaMessage` (Datensatz, siehe unten) |
| `$event->aktenzeichen` | Aktenzeichen des Empfängers, also das eigene (`26/0001`) |
| `$event->aktenzeichenAbsender` | Aktenzeichen des Absenders (Gericht, Behörde, Kollege) |
| `$event->data` | alle ausgelesenen Daten als Array, siehe [docs/Datenformat.md](docs/Datenformat.md) |
| `$event->fileIds` | File-IDs der Anhänge (inkl. Signaturdateien `.p7s`) |
| `$event->metaFileIds` | File-IDs von `zip`, `report` (Prüfprotokoll), `export` |
| `$event->attachments()` | Anhänge als `File`-Collection |

### Model `BeaMessage`

Spalten: `bea_id`, `osci_id`, `subject`, `sender_name`, `sender_safe_id`,
`sender_role`, `recipient_name`, `recipient_safe_id`, `aktenzeichen`,
`aktenzeichen_absender`, `sent_at`, `received_at` (Eingang Postfach),
`intermediary_at`, `secure_channel`, `eeb_requested`, `integrity`,
`vhn_signature`, `zip_file_id`, `assignable_type/_id`, `data`.

Methoden: `messageFiles()`, `attachments()`, `zipFile()`, `assignTo($model)`,
`isAssigned()`, `isVhnTrusted()`.

## Prüfungen

| Feld | Werte | Bedeutung |
|---|---|---|
| `integrity` | `complete` | jede Datei hat eine passende SHA-256-Prüfsumme im VHN und umgekehrt |
| | `incomplete` | Abweichung – Datei verändert, fehlt oder ist nicht erfasst |
| | `missing` | kein VHN in der Nachricht |
| `vhn_signature` | `valid` | Signatur gültig, Kette bis zu den hinterlegten BNotK-Zertifikaten |
| | `signature_ok` | Signatur mathematisch gültig, Kette nicht geprüft |
| | `invalid` | Signatur ungültig bzw. Kette nicht vertrauenswürdig |
| | `missing`, `notool`, `disabled` | – |
| `secure_channel` | `true`/`false` | „Sicherer Übermittlungsweg“ laut VHN |
| `eeb_requested` | `true` | XJustiz fordert ein elektronisches Empfangsbekenntnis an |

Für `valid` die Wurzel- und Zwischenzertifikate der BNotK-Zertifizierungsstelle
als PEM-Datei ablegen und `BEA_VHN_TRUST=/pfad/zu/bnotk-ca.pem` setzen.
Signaturdateien von Anhängen (`*.p7s`) werden mitgespeichert, aber nicht
selbst geprüft – dafür ist der Tab „PDF“ bzw. eine Signaturprüfung zuständig.

## Konfiguration (`.env`)

| Variable | Standard | Bedeutung |
|---|---|---|
| `BEA_INBOX_DISK` / `BEA_INBOX_PATH` | `local` / `bea-inbox` | Eingangsordner |
| `BEA_DELETE_AFTER_IMPORT` | `true` | importierte ZIPs löschen |
| `BEA_IMPORT_SCHEDULE` | – | Cron-Ausdruck für `bea:import` |
| `BEA_FILES_COLLECTION` | `bea` | `collection_name` der Dateien |
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
