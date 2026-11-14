<?php

use Nfgarching\Bea\Models\BeaMessage;

/*
 * Hilfsfunktion: packt die synthetische Testnachricht als ZIP.
 */
if (! function_exists('beaFixtureZip')) {
    /**
     * @param  array<string, string>  $extra  zusätzliche Dateien (Name => Inhalt) im Nachrichtenordner
     */
    function beaFixtureZip(array $extra = []): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'beazip').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $dir = __DIR__.'/Fixtures/Nachricht_100000001_Testnachricht';
        foreach (scandir($dir) as $name) {
            if (is_file($dir.'/'.$name)) {
                $zip->addFile($dir.'/'.$name, 'Nachricht_100000001_Testnachricht/'.$name);
            }
        }
        foreach ($extra as $name => $content) {
            $zip->addFromString('Nachricht_100000001_Testnachricht/'.$name, $content);
        }
        $zip->close();

        return $zipPath;
    }
}

/*
 * Hilfsfunktion: dieselbe Testnachricht als gesendete Nachricht (Export aus
 * dem Ordner „Gesendet“): Journal „… bei versandten Nachrichten“, ohne
 * „Eingang Postfach“, eigene beA-ID 100000009.
 */
if (! function_exists('beaFixtureZipOutgoing')) {
    function beaFixtureZipOutgoing(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'beazip').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $dir = __DIR__.'/Fixtures/Nachricht_100000001_Testnachricht';
        $folder = 'Nachricht_100000009_Gesendet/';
        foreach (scandir($dir) as $name) {
            if (! is_file($dir.'/'.$name)) {
                continue;
            }
            if (str_ends_with($name, '_export.html')) {
                $html = file_get_contents($dir.'/'.$name);
                $html = preg_replace('#<label class="col-form-label">Eingang Postfach:</label><div><span>[^<]*</span></div>#u', '', $html);
                $html = str_replace(
                    'Zeitpunkt des Empfangs (bei empfangenen Nachrichten)',
                    'Zeitpunkt des erfolgreichen Versands (bei versandten Nachrichten)',
                    $html,
                );
                $html = str_replace('100000001', '100000009', $html);
                $zip->addFromString($folder.$name, $html);

                continue;
            }
            $zip->addFile($dir.'/'.$name, $folder.$name);
        }
        $zip->close();

        return $zipPath;
    }
}

/*
 * Hilfsfunktion: einen Eintrag in data.files einer Nachricht ändern (früher
 * eine Zeile in bea_message_files).
 */
if (! function_exists('beaUpdateFile')) {
    function beaUpdateFile(BeaMessage $message, string $name, array $changes): void
    {
        $data = $message->data;
        foreach ($data['files'] as $i => $row) {
            if ($row['name'] === $name) {
                $data['files'][$i] = array_merge($row, $changes);
            }
        }
        $message->update(['data' => $data]);
        $message->forgetMessageFiles();
    }
}

/*
 * Hilfsfunktion: Sammel-ZIP wie der beA-Export mehrerer Nachrichten
 * (Posteingang.zip mit je einer Nachricht_….zip).
 */
if (! function_exists('beaFixtureZipCollection')) {
    /**
     * @param  array<string, string>  $inner  Name der inneren ZIP => Pfad
     */
    function beaFixtureZipCollection(array $inner): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'beazip').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($inner as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->close();

        return $zipPath;
    }
}
