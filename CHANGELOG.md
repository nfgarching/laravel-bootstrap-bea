# Changelog

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

- Import heruntergeladener beA-Nachrichten (ZIP, auch mehrere Nachrichten je ZIP) in zwei Schritten: **Einlesen** (`BeaImporter::ingest()`) erfasst und prüft nur; **Übernehmen** (`BeaImporter::accept()`) übergibt die von Hand gewählten Dateien an `nfgarching/files`.
- Eigene, nicht öffentliche Disk `beas`: Eingang `inbox/`, Originale `archive/{Jahr}` (Spalten `archive_*` in `bea_messages` mit SHA-256; keine eigene Tabelle), `failed/`.
- Einlesen per Eingang im Browser (`/bea/import`), Upload (`/bea/upload`) und Befehl `bea:import` (optional per Scheduler). Befehl und Scheduler übernehmen nie selbst.
- Übernahme (`/bea/{id}/select`): Auswahl mit Vorschlägen (`BeaClassifier`, Regeln in `config/bea.php`: Verfügungen, Schriftsätze und Schreiben vorausgewählt, Anlagen nicht) und Auswahl von Akte und Namen (`bea.assign.types`, Vorschlag anhand des Aktenzeichens).
- Signaturdateien (`.p7s`) und technische Dateien bleiben in der ZIP; signierte Dateien werden vermerkt, im Original übernommen und im Files-Package gesperrt. OCR-Text entsteht weiterhin.
- `BeaController` mit Liste (`/bea`, Filter nach Status, Zuordnung, Prüfung, Jahr) und Detailseite (`/bea/{id}`: Nachricht, Dateien, Prüfung, Journal), Download der Original-ZIP.
- Livewire-Komponenten `bea.import`, `bea.upload`, `bea.select` und `bea.listing` (zum Einbetten in Akten, Dokumente und Namen).
- Parser für XJustiz-Strukturdatensatz (Aktenzeichen, Instanz, Beteiligte, Dokumente, eEB-Anforderung), VHN, OSCI-Umschlag und beA-Exportansicht.
- Prüfung des Herkunftsnachweises: SHA-256-Prüfsummen und CMS-Signatur (OpenSSL, optional mit BNotK-Zertifikatskette).
- Prüfhistorie (`bea_verifications`) und erneute Prüfung gegen die Original-ZIP (`BeaIntegrityChecker`): ZIP unverändert, Dateien unverändert, VHN-Prüfsummen, VHN-Signatur. Eine Übernahme bricht ab, wenn ZIP oder Datei nicht mehr dem Stand beim Einlesen entsprechen.
- Events `BeaMessageImported` (nach dem Einlesen: Aktenzeichen und alle Daten, noch keine Dateien) und `BeaFilesAccepted` (nach der Übernahme); Übergabe an die Hauptzuordnung über `NewFiles` des Files-Packages.
- Sicheres Entpacken (keine Pfade außerhalb des Zielordners, keine Symlinks, Größengrenzen); Doppelimporte werden übersprungen.

### Changed

- Eine Zuordnung je Nachricht in `bea_messages.model` statt der Tabelle `bea_assignments`: beim Einlesen der User, nach der Übernahme das Modell der Anwendung (z. B. Dokument). In `bea.select` gewählte Akten/Namen gehen nur als `context` an `BeaFilesAccepted` (vorher `assignments`); `BeaImporter::accept()` nimmt `$context` und `target:`. Übernommene Files gehören immer der Nachricht (kein Umhängen, kein `NewFiles`-Event mehr); `BeaFilesAccepted` trägt zusätzlich `subject`. `BeaAssignment` und `primaryAssignment()` entfallen.
- Dateien mit den Endungen aus `bea.exclude_extensions` (Standard `xml`) werden nie übernommen; sie bleiben als Begleitdatei (`role` `meta`, `kind` `excluded`) in der ZIP. Jedes `technisches_pruefprotokoll*` gilt als Begleitdatei – bisher nur das erste einer Nachricht, weitere wurden als Anhang eingelesen und wegen „…protokoll“ als Verfügung vorgeschlagen.
- Tabelle `bea_message_files` entfällt: jede Datei der ZIP steht in `bea_messages.data.files` (`BeaMessage::messageFiles()` liefert `Data\BeaFile`), übernommene Dateien sind die Files der Nachricht (`BeaMessage::files()`, erkannt an `custom_properties.bea.zip_path`), neue Spalte `attachments_count`. `bea.file` und `accept()` nehmen die laufende Nummer `BeaFile::$id`. `scopeForOwners()` sucht nur noch über `bea_messages.model`.
- Sammel-ZIPs mit einer ZIP je Nachricht (beA-Export mehrerer Nachrichten, z. B. `Posteingang.zip`) werden aufgelöst statt mit „Keine beA-Nachricht gefunden“ abgelehnt; jede innere ZIP wird ein eigenes Archiv. `ingest()` liefert zusätzlich `archives`.
- Tabelle `bea_archives` entfällt: die Original-ZIP steht in den Spalten `archive_*` von `bea_messages` (`$message->archive` liefert `Data\BeaZip`); `BeaStorage::storeArchive()` gibt ein `BeaZip` zurück und erkennt inhaltsgleiche ZIPs an `archive_sha256`.
