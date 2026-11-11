<?php

namespace Nfgarching\Bea\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Nfgarching\Bea\Livewire\Concerns\IngestsArchives;
use Nfgarching\Bea\Services\BeaStorage;

/**
 * Einlesen aus dem Eingang der Disk „beas“ (wie der Scan-Eingang des
 * Files-Packages): ZIPs auflisten, auswählen, einlesen oder löschen.
 */
class Import extends Component
{
    use IngestsArchives;

    public string $sort = 'lastModified';

    public string $direction = 'desc';

    public bool $selectAll = false;

    /** @var list<string> */
    public array $selectedFiles = [];

    public function mount(?string $modelType = null, ?int $modelId = null): void
    {
        $this->modelType = $modelType;
        $this->modelId = $modelId;
    }

    /**
     * @return list<array{path: string, name: string, size: int, modified: int}>
     */
    #[Computed]
    public function files(): array
    {
        $storage = app(BeaStorage::class);
        $disk = $storage->disk();

        $files = array_map(fn (string $path) => [
            'path' => $path,
            'name' => basename($path),
            'size' => (int) $disk->size($path),
            'modified' => (int) $disk->lastModified($path),
        ], $storage->inboxFiles());

        $key = match ($this->sort) {
            'size' => 'size',
            'name' => 'name',
            default => 'modified',
        };
        usort($files, fn ($a, $b) => $a[$key] <=> $b[$key]);

        return $this->direction === 'desc' ? array_reverse($files) : $files;
    }

    public function sortBy(string $field): void
    {
        if ($this->sort === $field) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $field;
            $this->direction = 'asc';
        }
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selectedFiles = $value ? array_column($this->files, 'path') : [];
    }

    public function deleteFile(string $file): void
    {
        $storage = app(BeaStorage::class);
        if ($storage->isInboxFile($file)) {
            $storage->disk()->delete($file);
        }
        $this->selectedFiles = array_values(array_diff($this->selectedFiles, [$file]));
        unset($this->files);
    }

    /**
     * Liest die markierten ZIPs ein. Eingelesene ZIPs liegen danach im Archiv
     * der Disk und werden aus dem Eingang entfernt, fehlerhafte wandern nach failed/.
     */
    public function ingest(): void
    {
        $storage = app(BeaStorage::class);
        $this->results = [];

        foreach ($this->selectedFiles as $file) {
            if (! $storage->isInboxFile($file) || ! $storage->disk()->exists($file)) {
                continue;
            }

            $local = null;
            try {
                $local = $storage->localCopy($storage->diskName(), $file);
                if ($this->ingestLocal($local, basename($file), 'inbox')) {
                    $storage->disk()->delete($file);
                } else {
                    $storage->moveToFailed($file);
                }
            } catch (\Throwable $e) {
                $this->results[] = ['type' => 'danger', 'text' => basename($file).': '.$e->getMessage(), 'message_id' => null];
            } finally {
                if ($local) {
                    @unlink($local);
                }
            }
        }

        $this->selectedFiles = [];
        $this->selectAll = false;
        unset($this->files);
    }

    public function render(): View
    {
        return view('bea::livewire.import', [
            'diskName' => app(BeaStorage::class)->diskName(),
            'inboxPath' => app(BeaStorage::class)->inboxPath(),
        ]);
    }
}
