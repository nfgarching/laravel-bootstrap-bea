# Changelog

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

- Import heruntergeladener beA-Nachrichten (ZIP, auch mehrere Nachrichten je ZIP) per Upload (`/bea`) und Befehl `bea:import` (Eingangsordner, optional per Scheduler).
- Parser für XJustiz-Strukturdatensatz (Aktenzeichen, Instanz, Beteiligte, Dokumente, eEB-Anforderung), VHN, OSCI-Umschlag und beA-Exportansicht.
- Prüfung des Herkunftsnachweises: SHA-256-Prüfsummen und CMS-Signatur (OpenSSL, optional mit BNotK-Zertifikatskette).
- Ablage von ZIP, Anhängen, Prüfprotokoll und Exportansicht über `nfgarching/files`; Doppelimporte werden übersprungen.
- Event `BeaMessageImported` mit Aktenzeichen, Absender-Aktenzeichen, allen Daten und Datei-IDs; `BeaMessage::assignTo()` für die Zuordnung im eigenen Listener.
- Sicheres Entpacken (keine Pfade außerhalb des Zielordners, keine Symlinks, Größengrenzen).
