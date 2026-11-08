<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Eingangsordner
    |--------------------------------------------------------------------------
    | Heruntergeladene beA-Nachrichten (ZIP) werden hier abgelegt und mit
    | `php artisan bea:import` eingelesen. Erfolgreich importierte ZIPs
    | werden gelöscht (delete_after_import), fehlerhafte nach failed/
    | verschoben. Die ZIP selbst bleibt als Datei im Files-Package erhalten.
    */

    'inbox' => [
        'disk' => env('BEA_INBOX_DISK', 'local'),
        'path' => env('BEA_INBOX_PATH', 'bea-inbox'),
        'delete_after_import' => env('BEA_DELETE_AFTER_IMPORT', true),
        // Cron-Ausdruck für den automatischen Import, leer = aus
        'schedule' => env('BEA_IMPORT_SCHEDULE'),
    ],

    /*
    | Ablage im Files-Package (collection_name der File-Datensätze).
    | Anhänge sind nie „eigene Dokumente“: der empfangene Stand ist Beweis.
    */
    'collection' => env('BEA_FILES_COLLECTION', 'bea'),

    // Zusätzlich zu den Anhängen ablegen
    'store' => [
        'zip' => true,                  // ganze ZIP als Beweisstück
        'verification_report' => true,  // beA-Prüfprotokoll (PDF)
        'export' => true,                // beA-Exportansicht (HTML)
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
