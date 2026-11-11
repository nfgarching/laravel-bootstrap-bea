<?php

namespace Nfgarching\Bea\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Bea\Exceptions\InvalidBeaArchive;
use Nfgarching\Bea\Models\BeaArchive;

/**
 * Disk „beas“: Eingang (inbox), Originale (archive/{Jahr}) und nicht
 * einlesbare ZIPs (failed).
 */
class BeaStorage
{
    public function diskName(): string
    {
        return (string) config('bea.disk', 'beas');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function inboxPath(): string
    {
        return trim((string) config('bea.inbox.path', 'inbox'), '/');
    }

    public function failedPath(): string
    {
        return trim((string) config('bea.failed.path', 'failed'), '/');
    }

    /**
     * ZIP-Dateien im Eingang (Pfade relativ zur Disk).
     *
     * @return list<string>
     */
    public function inboxFiles(): array
    {
        $files = array_filter($this->disk()->files($this->inboxPath()), fn ($f) => str_ends_with(strtolower($f), '.zip'));
        sort($files);

        return array_values($files);
    }

    /**
     * Liegt der Pfad im Eingang? Schutz gegen manipulierte Pfade aus dem Browser.
     */
    public function isInboxFile(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return ! str_contains($path, '..')
            && str_starts_with($path, $this->inboxPath().'/')
            && str_ends_with(strtolower($path), '.zip');
    }

    /**
     * Legt die ZIP unverändert im Archiv ab. Eine inhaltsgleiche ZIP
     * (gleiche SHA-256) wird nicht doppelt gespeichert.
     */
    public function storeArchive(string $localZip, string $originalName, string $source = 'upload'): BeaArchive
    {
        $sha256 = hash_file('sha256', $localZip);
        if ($sha256 === false) {
            throw new InvalidBeaArchive('ZIP-Datei nicht lesbar: '.$originalName);
        }

        $existing = BeaArchive::where('sha256', $sha256)->first();
        if ($existing && $this->exists($existing)) {
            return $existing;
        }

        $path = trim((string) config('bea.archive.path', 'archive'), '/')
            .'/'.date('Y').'/'.substr($sha256, 0, 12).'_'.$originalName;

        $stream = fopen($localZip, 'rb');
        try {
            if ($stream === false || ! $this->disk()->writeStream($path, $stream)) {
                throw new InvalidBeaArchive('ZIP-Datei ließ sich nicht auf der Disk „'.$this->diskName().'“ ablegen.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $attributes = [
            'disk' => $this->diskName(),
            'path' => $path,
            'original_name' => $originalName,
            'size' => filesize($localZip),
            'source' => $source,
            'imported_by' => auth()->id(),
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return BeaArchive::create($attributes + ['sha256' => $sha256]);
    }

    public function exists(BeaArchive $archive): bool
    {
        return Storage::disk($archive->disk)->exists($archive->path);
    }

    /**
     * Kopiert eine Datei der Disk in eine lokale Temp-Datei (die Disk kann
     * auch entfernt liegen). Der Aufrufer löscht die Datei.
     */
    public function localCopy(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new InvalidBeaArchive("Datei nicht lesbar: {$disk}/{$path}");
        }

        $local = tempnam(sys_get_temp_dir(), 'bea');
        try {
            file_put_contents($local, $stream);
        } finally {
            fclose($stream);
        }

        return $local;
    }

    public function localArchive(BeaArchive $archive): string
    {
        if (! $this->exists($archive)) {
            throw new InvalidBeaArchive('Original-ZIP fehlt auf der Disk: '.$archive->path);
        }

        return $this->localCopy($archive->disk, $archive->path);
    }

    /**
     * Verschiebt eine nicht einlesbare ZIP aus dem Eingang nach failed/.
     */
    public function moveToFailed(string $inboxFile): void
    {
        $this->disk()->move($inboxFile, $this->failedPath().'/'.date('Ymd-His').'_'.basename($inboxFile));
    }
}
