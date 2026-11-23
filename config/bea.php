<?php

return [

    /*
    | Icon des Packages (Bootstrap-Icons-Klassen): Navbar-Button und der
    | Create-Button „bea-import“ der Anwendung.
    */
    'icon' => env('BEA_ICON', 'bi bi-bootstrap'),

    /*
    |--------------------------------------------------------------------------
    | Ablage der beA-Exporte (ZIP)
    |--------------------------------------------------------------------------
    | BEA_DISK ist die Disk der Originale (jede eingelesene ZIP unverändert,
    | Beweisstück) und die Vorgabe für Eingang und failed. Standard „local“
    | (storage/app/private). Ist die Disk in config/filesystems.php nicht
    | definiert, legt das Package sie lokal unter BEA_DISK_ROOT (sonst
    | storage/app/beas) an.
    |
    | Ordner der Originale wie der PathGenerator des Files-Packages:
    |
    |   [{Präfix}/][{Jahr}/][{Suffix}/]{BEA_PATH}/{Jahr}-{Monat}[/{Postfix}]
    |
    | Die Jahresebene vor BEA_PATH lässt sich mit BEA_DIR_YEAR=false
    | abschalten. Beispiele:
    |   Standard                          local: 2026/beas/2026-11
    |   BEA_DISK=data, BEA_DIR_SUFFIX=akv data:  2026/akv/beas/2026-11
    |   BEA_DIR_YEAR=false                local: beas/2026-11
    |
    | Gespeichert wird je Nachricht Disk und Pfad (archive_disk/archive_path):
    | Eine geänderte Einstellung gilt nur für neu eingelesene ZIPs.
    */

    'disk' => env('BEA_DISK', 'local'),
    'disk_root' => env('BEA_DISK_ROOT'),

    'archive' => [
        'path' => env('BEA_PATH', 'beas'),
        // Jahresebene vor BEA_PATH ({Jahr}/…/beas/{Jahr}-{Monat})
        'year' => env('BEA_DIR_YEAR', true),
        'prefix' => env('BEA_DIR_PREFIX', ''),
        'suffix' => env('BEA_DIR_SUFFIX', ''),
        'postfix' => env('BEA_DIR_POSTFIX', ''),
    ],

    'inbox' => [
        // Eingang: heruntergeladene ZIPs; eigene Disk z. B. „scans“, leer = BEA_DISK
        'disk' => env('BEA_INBOX_DISK'),
        'path' => env('BEA_INBOX_PATH', 'beas/inbox'),
        // Cron-Ausdruck für das automatische Einlesen, leer = aus
        'schedule' => env('BEA_IMPORT_SCHEDULE'),
    ],

    'failed' => [
        // ZIPs, die sich nicht einlesen ließen; leer = BEA_DISK
        'disk' => env('BEA_FAILED_DISK'),
        'path' => env('BEA_FAILED_PATH', 'beas/failed'),
    ],

    /*
    | Ablage der übernommenen Dateien im Files-Package (collection_name).
    | Übernommene Dateien sind immer „Original Dokumente“: der empfangene
    | Stand bleibt Hauptdatei, OCR-Text entsteht daneben.
    */
    'collection' => env('BEA_FILES_COLLECTION', 'bea'),

    /*
    |--------------------------------------------------------------------------
    | Vorschläge für die Übernahme
    |--------------------------------------------------------------------------
    | Die Regeln werden von oben nach unten geprüft, die erste passende gilt.
    | „pattern“ wird auf Anzeigename (XJustiz) und Dateiname angewandt,
    | „classes“ auf den XJustiz-Code der Dokumentklasse, „extensions“ auf die
    | Dateiendung. „suggest“ = bei der Übernahme vorausgewählt.
    |
    | Signaturdateien (.p7s) und technische Dateien (XJustiz, VHN, Export,
    | Prüfprotokolle) bleiben immer in der ZIP und stehen nicht zur Auswahl.
    */
    'suggest' => [
        'rules' => [
            [
                'category' => 'anlage',
                'suggest' => false,
                'pattern' => '/^\s*(anlagen?|anl\.|anlagenkonvolut|[KB]\s?\d+)\b/iu',
            ],
            [
                'category' => 'verfuegung',
                'suggest' => true,
                'classes' => [],
                'pattern' => '/verf(ü|ue)gung|beschlu(ss|ß)|urteil|ladung|protokoll|hinweis|terminsbestimmung|bescheid|kostenfestsetzung|kostenrechnung|empfangsbekenntnis|zustellung/iu',
            ],
            [
                'category' => 'schriftsatz',
                'suggest' => true,
                'classes' => [],
                'pattern' => '/schriftsatz|klage|erwiderung|replik|duplik|berufung|beschwerde|antrag|stellungnahme|widerspruch|einspruch|begr(ü|ue)ndung/iu',
            ],
            [
                'category' => 'schreiben',
                'suggest' => true,
                'classes' => [],
                'pattern' => '/schreiben|mitteilung|brief|nachricht|anfrage|auskunft/iu',
            ],
        ],
        // Keine Regel passt: Dateien mit diesen Endungen trotzdem vorschlagen
        // (lieber eine Datei zu viel als eine Verfügung zu wenig)
        'fallback_extensions' => ['pdf'],
    ],

    /*
    | Dateiendungen, die nie in das Files-Package übernommen werden (auch nicht
    | bei Vorschlag oder Auswahl). Sie bleiben als Begleitdatei in der ZIP und
    | stehen im Reiter „Dateien“ der Nachricht.
    */
    'exclude_extensions' => array_values(array_filter(array_map('trim', explode(',', (string) env('BEA_EXCLUDE_EXTENSIONS', 'xml'))))),

    'categories' => [
        'verfuegung' => 'Verfügung/Entscheidung',
        'schriftsatz' => 'Schriftsatz',
        'schreiben' => 'Schreiben',
        'anlage' => 'Anlage',
        'sonstiges' => 'Sonstiges',
    ],

    /*
    |--------------------------------------------------------------------------
    | Zuordnung bei der Übernahme
    |--------------------------------------------------------------------------
    | Eine Nachricht hat genau eine Zuordnung (bea_messages.model): beim
    | Einlesen den User, nach der Übernahme das Modell, das die Anwendung
    | anlegt bzw. aus dem heraus übernommen wird (z. B. ein Dokument). An sie
    | gehen die übernommenen Dateien (NewFiles-Event).
    |
    | Die hier eingetragenen Modelle lassen sich in bea.select suchen und
    | wählen; sie gehen als Kontext ($context) mit dem Event BeaFilesAccepted
    | an die Anwendung, z. B. Akte und Name für ein neues Dokument.
    |
    |   'akte' => [
    |       'label' => 'Akte',
    |       'model' => App\Models\Akte::class,
    |       'search' => ['aktenzeichen', 'bezeichnung'],  // Spalten für die Suche
    |       'display' => 'aktenzeichen',                   // Spalte/Attribut für die Anzeige
    |       'match' => 'aktenzeichen',                     // Spalte für den Vorschlag anhand des Aktenzeichens
    |   ],
    |   'name' => [
    |       'label' => 'Name',
    |       'model' => App\Models\Names\Name::class,
    |       'search' => ['name', 'vorname'],
    |       'display' => 'name',
    |   ],
    */
    'assign' => [
        'types' => [],
        // Muster, mit dem das eigene Aktenzeichen aus dem Freitext des Absenders gelesen wird
        'aktenzeichen_pattern' => '#\d{2}/\d{3,5}#',
        // Übernehmen im Modal/Popup (bea.pending): Schlüssel aus „data“ des
        // Aufrufs, die als Kontext mitgegeben werden, z. B.
        //   'Register_ID' => ['type' => 'akte', 'column' => 'ID'],
        'context' => [],
        // Typ (Schlüssel aus „types“), den der Listener auf BeaFilesAccepted ohne
        // Ziel neu anlegt – nur für die Anzeige „neu: …“ in bea.select; null = keine.
        'new' => null,
    ],

    /*
    | Morph-Typ des Users (wie files.default_model_type): Aufrufkontext ohne
    | model_type; eine Zuordnung zu diesem Typ gilt nicht als Zuordnung zu
    | einem Modell der Anwendung (Dateien bleiben bei der Nachricht).
    */
    'default_model_type' => env('BEA_DEFAULT_MODEL_TYPE', 'App\\Models\\User'),

    /*
    | Eigene SAFE-IDs (kommagetrennt): erkennt gesendete Nachrichten, wenn das
    | Journal der Exportansicht fehlt. Normalerweise genügt das Journal
    | („Zeitpunkt des erfolgreichen Versands (bei versandten Nachrichten)“).
    */
    'own_safe_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('BEA_OWN_SAFE_IDS', ''))))),

    /*
    | Herkunftsnachweis (vhn.xml.p7s) mit OpenSSL prüfen. Mit der PEM-Datei
    | der BNotK-Zertifikate wird auch die Kette geprüft (valid); Standard ist
    | die mitgelieferte „beA VHN CA 2017“ (resources/certs, als Anker per
    | -partial_chain). BEA_VHN_TRUST= (leer) prüft nur mathematisch
    | (signature_ok); relative Pfade gelten ab base_path().
    */
    'vhn' => [
        'verify' => env('BEA_VHN_VERIFY', true),
        'openssl' => env('BEA_OPENSSL_BINARY', 'openssl'),
        'trust' => env('BEA_VHN_TRUST', dirname(__DIR__).'/resources/certs/2021-09-09-bea-vhn-ca-2017.pem'),
    ],

    'upload' => [
        'max_kb' => (int) env('BEA_UPLOAD_MAX_KB', 204800), // beA erlaubt bis 200 MB je Nachricht
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'bea',
        'middleware' => ['web', 'auth'],
    ],
];
