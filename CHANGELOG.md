# Changelog

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

- Import heruntergeladener beA-Nachrichten (ZIP, auch mehrere Nachrichten je ZIP) in zwei Schritten: **Einlesen** (`BeaImporter::ingest()`) erfasst und prüft nur; **Übernehmen** (`BeaImporter::accept()`) übergibt die von Hand gewählten Dateien an `nfgarching/files`.
- Eigene, nicht öffentliche Disk `beas`: Eingang `inbox/`, Originale `archive/{Jahr}` (Tabelle `bea_archives` mit SHA-256), `failed/`.
- Einlesen per Eingang im Browser (`/bea/import`), Upload (`/bea/upload`) und Befehl `bea:import` (optional per Scheduler). Befehl und Scheduler übernehmen nie selbst.
- Übernahme (`/bea/{id}/select`): Auswahl mit Vorschlägen (`BeaClassifier`, Regeln in `config/bea.php`: Verfügungen, Schriftsätze und Schreiben vorausgewählt, Anlagen nicht) und Zuordnung zu Akte und Namen (`bea.assign.types`, mehrere Zuordnungen je Nachricht, Vorschlag anhand des Aktenzeichens).
- Signaturdateien (`.p7s`) und technische Dateien bleiben in der ZIP; signierte Dateien werden vermerkt, im Original übernommen und im Files-Package gesperrt. OCR-Text entsteht weiterhin.
- `BeaController` mit Liste (`/bea`, Filter nach Status, Zuordnung, Prüfung, Jahr) und Detailseite (`/bea/{id}`: Nachricht, Dateien, Prüfung, Journal), Download der Original-ZIP.
- Livewire-Komponenten `bea.import`, `bea.upload`, `bea.select` und `bea.listing` (zum Einbetten in Akten, Dokumente und Namen).
- Parser für XJustiz-Strukturdatensatz (Aktenzeichen, Instanz, Beteiligte, Dokumente, eEB-Anforderung), VHN, OSCI-Umschlag und beA-Exportansicht.
- Prüfung des Herkunftsnachweises: SHA-256-Prüfsummen und CMS-Signatur (OpenSSL, optional mit BNotK-Zertifikatskette).
- Prüfhistorie (`bea_verifications`) und erneute Prüfung gegen die Original-ZIP (`BeaIntegrityChecker`): ZIP unverändert, Dateien unverändert, VHN-Prüfsummen, VHN-Signatur. Eine Übernahme bricht ab, wenn ZIP oder Datei nicht mehr dem Stand beim Einlesen entsprechen.
- Events `BeaMessageImported` (nach dem Einlesen: Aktenzeichen und alle Daten, noch keine Dateien) und `BeaFilesAccepted` (nach der Übernahme); Übergabe an die Hauptzuordnung über `NewFiles` des Files-Packages.
- Sicheres Entpacken (keine Pfade außerhalb des Zielordners, keine Symlinks, Größengrenzen); Doppelimporte werden übersprungen.
