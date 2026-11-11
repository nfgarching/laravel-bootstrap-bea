<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disk „beas“
    |--------------------------------------------------------------------------
    | Eigene, nicht öffentliche Disk für beA-Exporte (ZIP). Ist die Disk in
    | config/filesystems.php nicht definiert, legt das Package sie lokal unter
    | storage/app/beas (bzw. BEA_DISK_ROOT) an.
    |
    |   inbox/           Eingang: heruntergeladene ZIPs, wie „scans“ im Files-Package
    |   archive/{Jahr}/  Originale: jede eingelesene ZIP unverändert (Beweisstück)
    |   failed/          ZIPs, die sich nicht einlesen ließen
    */

    'disk' => env('BEA_DISK', 'beas'),
    'disk_root' => env('BEA_DISK_ROOT'),

    'inbox' => [
        'path' => env('BEA_INBOX_PATH', 'inbox'),
        // Cron-Ausdruck für das automatische Einlesen, leer = aus
        'schedule' => env('BEA_IMPORT_SCHEDULE'),
    ],

    'archive' => [
        'path' => env('BEA_ARCHIVE_PATH', 'archive'),
    ],

    'failed' => [
        'path' => env('BEA_FAILED_PATH', 'failed'),
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
    | Modelle der Anwendung, denen eine Nachricht zugeordnet werden kann. Der
    | erste Eintrag einer Nachricht ist die Hauptzuordnung: an ihn werden die
    | übernommenen Dateien per NewFiles-Event übergeben.
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
    ],

    /*
    | Herkunftsnachweis (vhn.xml.p7s) mit OpenSSL prüfen. Ohne trust wird nur
    | die Signatur mathematisch geprüft (Status signature_ok); mit einer
    | PEM-Datei der BNotK-Wurzel-/Zwischenzertifikate auch die Kette (valid).
    */
    'vhn' => [
        'verify' => env('BEA_VHN_VERIFY', true),
        'openssl' => env('BEA_OPENSSL_BINARY', 'openssl'),
        'trust' => env('BEA_VHN_TRUST'),
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
