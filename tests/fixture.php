<?php

/*
 * Hilfsfunktion: packt die synthetische Testnachricht als ZIP.
 */
if (! function_exists('beaFixtureZip')) {
    function beaFixtureZip(): string
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
