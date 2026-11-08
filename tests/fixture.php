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
