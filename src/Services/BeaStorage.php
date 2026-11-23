<?php

namespace Nfgarching\Bea\Services;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Nfgarching\Bea\Data\BeaZip;
use Nfgarching\Bea\Exceptions\InvalidBeaArchive;
use Nfgarching\Bea\Models\BeaMessage;

/**
 * Ablage der beA-Exporte: Originale auf BEA_DISK (Ordner siehe
 * archiveDirectory()), Eingang (inbox) und nicht einlesbare ZIPs (failed)
 * auf eigener Disk oder ebenfalls auf BEA_DISK.
 */
class BeaStorage
{
    public function diskName(): string
    {
        return (string) config('bea.disk', 'local');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Disk des Eingangs; ohne eigene Angabe BEA_DISK.
     */
    public function inboxDiskName(): string
    {
        return (string) (config('bea.inbox.disk') ?: $this->diskName());
    }

    public function inboxDisk(): Filesystem
    {
        return Storage::disk($this->inboxDiskName());
    }

    public function deleteInboxFile(string $inboxFile): void
    {
        $this->inboxDisk()->delete($inboxFile);
    }

    public function inboxPath(): string
    {
        return trim((string) config('bea.inbox.path', 'beas/inbox'), '/');
    }

    /**
     * Disk für nicht einlesbare ZIPs; ohne eigene Angabe BEA_DISK.
     */
    public function failedDiskName(): string
    {
        return (string) (config('bea.failed.disk') ?: $this->diskName());
    }

    public function failedDisk(): Filesystem
    {
        return Storage::disk($this->failedDiskName());
    }

    public function failedPath(): string
    {
        return trim((string) config('bea.failed.path', 'beas/failed'), '/');
    }

    /**
     * Ordner der Originale auf BEA_DISK, aufgebaut wie im PathGenerator des
     * Files-Packages:
     *
     *   [{Präfix}/][{Jahr}/][{Suffix}/]{BEA_PATH}/{Jahr}-{Monat}[/{Postfix}]
     *
     * Die Jahresebene vor BEA_PATH lässt sich mit BEA_DIR_YEAR=false
     * abschalten (Standard „2026/beas/2026-11“, mit Suffix „akv“
     * „2026/akv/beas/2026-11“, ohne Jahresebene „beas/2026-11“).
     */
    public function archiveDirectory(?int $timestamp = null): string
    {
        $timestamp ??= time();
        $part = fn (string $key, string $default = ''): string => trim((string) config("bea.archive.{$key}", $default), '/');
        $year = filter_var(config('bea.archive.year', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

        return implode('/', array_filter([
            $part('prefix'),
            $year ? date('Y', $timestamp) : '',
            $part('suffix'),
            $part('path', 'beas'),
            date('Y-m', $timestamp),
            $part('postfix'),
        ], fn (string $segment): bool => $segment !== ''));
    }

    /**
     * ZIP-Dateien im Eingang (Pfade relativ zur Disk).
     *
     * @return list<string>
     */
    public function inboxFiles(): array
    {
        $files = array_filter($this->inboxDisk()->files($this->inboxPath()), fn ($f) => str_ends_with(strtolower($f), '.zip'));
        sort($files);

        return array_values($files);
    }

    /**
     * Liegt der Pfad im Eingang? Schutz gegen manipulierte Pfade aus dem Browser.
     */
    public function isInboxFile(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        // Nur Dateien direkt im Eingang – Archiv/failed dürfen darunter liegen
        return ! str_contains($path, '..')
            && dirname($path) === ($this->inboxPath() === '' ? '.' : $this->inboxPath())
            && str_ends_with(strtolower($path), '.zip');
    }

    /**
     * Legt die ZIP unverändert im Archiv ab. Eine inhaltsgleiche ZIP
     * (gleiche SHA-256), die schon auf der Disk liegt, wird nicht doppelt
     * gespeichert – die Nachrichten verweisen dann auf dieselbe Datei.
     */
    public function storeArchive(string $localZip, string $originalName, string $source = 'upload'): BeaZip
    {
        $sha256 = hash_file('sha256', $localZip);
        if ($sha256 === false) {
            throw new InvalidBeaArchive('ZIP-Datei nicht lesbar: '.$originalName);
        }

        $known = BeaMessage::query()->where('archive_sha256', $sha256)->whereNotNull('archive_path')->orderBy('id')->first();
        if ($known?->archive && $this->exists($known->archive)) {
            return $known->archive;
        }

        $path = $this->archiveDirectory().'/'.substr($sha256, 0, 12).'_'.$originalName;

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

        return new BeaZip(
            disk: $this->diskName(),
            path: $path,
            original_name: $originalName,
            sha256: $sha256,
            size: (int) filesize($localZip),
            source: $source,
        );
    }

    public function exists(BeaZip $archive): bool
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

    public function localArchive(BeaZip $archive): string
    {
        if (! $this->exists($archive)) {
            throw new InvalidBeaArchive('Original-ZIP fehlt auf der Disk: '.$archive->path);
        }

        return $this->localCopy($archive->disk, $archive->path);
    }

    /**
     * Ruft $callback mit einem lokalen Pfad der Original-ZIP auf (nur lesen!):
     * bei lokaler Disk die Datei selbst, sonst eine Temp-Kopie, die danach
     * gelöscht wird. Der Aufrufer löscht den Pfad nie selbst.
     *
     * @template T
     *
     * @param  Closure(string): T  $callback
     * @return T
     */
    public function withLocalArchive(BeaZip $archive, Closure $callback): mixed
    {
        $direct = $this->directPath($archive->disk, $archive->path);
        if ($direct !== null) {
            return $callback($direct);
        }

        $local = $this->localArchive($archive);
        try {
            return $callback($local);
        } finally {
            @unlink($local);
        }
    }

    /**
     * Echter Pfad einer Datei auf einer lokalen Disk, sonst null.
     */
    private function directPath(string $disk, string $path): ?string
    {
        $filesystem = Storage::disk($disk);
        if (! $filesystem instanceof FilesystemAdapter || ! $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            return null;
        }

        $full = $filesystem->path($path);

        return is_file($full) ? $full : null;
    }

    /**
     * Verschiebt eine nicht einlesbare ZIP aus dem Eingang nach failed/ –
     * auch wenn Eingang und failed auf verschiedenen Disks liegen.
     */
    public function moveToFailed(string $inboxFile): void
    {
        $target = $this->failedPath().'/'.date('Ymd-His').'_'.basename($inboxFile);

        if ($this->inboxDiskName() === $this->failedDiskName()) {
            $this->failedDisk()->move($inboxFile, $target);

            return;
        }

        $stream = $this->inboxDisk()->readStream($inboxFile);
        if (! is_resource($stream)) {
            throw new InvalidBeaArchive('Datei nicht lesbar: '.$this->inboxDiskName().'/'.$inboxFile);
        }
        try {
            if (! $this->failedDisk()->writeStream($target, $stream)) {
                throw new InvalidBeaArchive('ZIP-Datei ließ sich nicht nach „'.$this->failedPath().'“ verschieben.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->deleteInboxFile($inboxFile);
    }
}
