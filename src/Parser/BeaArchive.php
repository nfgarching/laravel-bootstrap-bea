<?php

namespace Nfgarching\Bea\Parser;

use Nfgarching\Bea\Exceptions\InvalidBeaArchive;
use ZipArchive;

/**
 * Entpackt einen beA-Export sicher: keine absoluten Pfade, kein "..",
 * keine symbolischen Links, Größen- und Anzahlgrenzen gegen ZIP-Bomben.
 */
final class BeaArchive
{
    public function __construct(
        private readonly int $maxFiles = 500,
        private readonly int $maxBytes = 1073741824, // 1 GB entpackt
    ) {}

    /**
     * @return list<string> relative Pfade der entpackten Dateien
     */
    public function extract(string $zipPath, string $target): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new InvalidBeaArchive('ZIP-Datei lässt sich nicht öffnen: '.basename($zipPath));
        }

        try {
            if ($zip->numFiles > $this->maxFiles) {
                throw new InvalidBeaArchive("ZIP enthält mehr als {$this->maxFiles} Dateien.");
            }

            if (! is_dir($target) && ! mkdir($target, 0700, true) && ! is_dir($target)) {
                throw new InvalidBeaArchive('Arbeitsverzeichnis lässt sich nicht anlegen.');
            }

            $extracted = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $relative = $this->safePath($name);

                // Unix-Symlinks (S_IFLNK) nicht entpacken
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX
                    && (($attr >> 16) & 0170000) === 0120000) {
                    continue;
                }

                $total += (int) ($stat['size'] ?? 0);
                if ($total > $this->maxBytes) {
                    throw new InvalidBeaArchive('ZIP ist entpackt zu groß.');
                }

                $destination = $target.'/'.$relative;
                if (! is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0700, true);
                }

                $in = $zip->getStream($stat['name']);
                if ($in === false) {
                    throw new InvalidBeaArchive("Datei im ZIP nicht lesbar: {$relative}");
                }
                $out = fopen($destination, 'wb');
                $written = stream_copy_to_stream($in, $out, (int) $stat['size'] + 1);
                fclose($in);
                fclose($out);
                if ($written > (int) $stat['size']) {
                    throw new InvalidBeaArchive("Größenangabe im ZIP stimmt nicht: {$relative}");
                }

                $extracted[] = $relative;
            }

            return $extracted;
        } finally {
            $zip->close();
        }
    }

    /**
     * Liest eine einzelne Datei aus der ZIP, ohne alles zu entpacken – z. B.
     * für die Vorschau vor dem Übernehmen. $relative ist der Pfad, wie ihn
     * extract() liefert (bea_message_files.zip_path). null, wenn es die Datei
     * nicht gibt oder sie größer als $maxBytes ist.
     */
    public function read(string $zipPath, string $relative, int $maxBytes = 104857600): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new InvalidBeaArchive('ZIP-Datei lässt sich nicht öffnen: '.basename($zipPath));
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                try {
                    if ($this->safePath($name) !== $relative) {
                        continue;
                    }
                } catch (InvalidBeaArchive) {
                    continue;
                }

                if ((int) ($stat['size'] ?? 0) > $maxBytes) {
                    return null;
                }

                $content = $zip->getFromIndex($i, $maxBytes + 1);

                return $content === false || strlen($content) > $maxBytes ? null : $content;
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    private function safePath(string $name): string
    {
        $parts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || str_contains($part, "\0")) {
                throw new InvalidBeaArchive("Unzulässiger Pfad im ZIP: {$name}");
            }
            $parts[] = $part;
        }
        if ($parts === [] || preg_match('/^[A-Za-z]:$/', $parts[0])) {
            throw new InvalidBeaArchive("Unzulässiger Pfad im ZIP: {$name}");
        }

        return implode('/', $parts);
    }
}
