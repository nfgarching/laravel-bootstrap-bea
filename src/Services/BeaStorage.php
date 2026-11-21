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

    /**
     * Disk des Eingangs; ohne eigene Angabe die Disk „beas“.
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
     * Verschiebt eine nicht einlesbare ZIP aus dem Eingang nach failed/ der
     * Disk „beas“ – auch wenn der Eingang auf einer anderen Disk liegt.
     */
    public function moveToFailed(string $inboxFile): void
    {
        $target = $this->failedPath().'/'.date('Ymd-His').'_'.basename($inboxFile);

        if ($this->inboxDiskName() === $this->diskName()) {
            $this->disk()->move($inboxFile, $target);

            return;
        }

        $stream = $this->inboxDisk()->readStream($inboxFile);
        if (! is_resource($stream)) {
            throw new InvalidBeaArchive('Datei nicht lesbar: '.$this->inboxDiskName().'/'.$inboxFile);
        }
        try {
            if (! $this->disk()->writeStream($target, $stream)) {
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
