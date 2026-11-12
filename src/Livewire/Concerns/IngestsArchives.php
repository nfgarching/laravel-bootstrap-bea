<?php

namespace Nfgarching\Bea\Livewire\Concerns;

use Livewire\Attributes\Locked;
use Nfgarching\Bea\Services\AssignTypes;
use Nfgarching\Bea\Services\BeaImporter;

/**
 * Gemeinsames Einlesen für Verzeichnis-Import und Upload: Ergebnisliste und
 * Vorbelegung der Zuordnung, wenn die Seite aus einer Akte, einem Dokument
 * oder Namen heraus geöffnet wurde.
 */
trait IngestsArchives
{
    #[Locked]
    public ?string $modelType = null;

    #[Locked]
    public ?int $modelId = null;

    /**
     * Im Modal der Liste: ohne Knopf „Übernehmen“ in den Ergebnissen; nach dem
     * Einlesen meldet das Browser-Event „bea-ingested“, dass die Liste beim
     * Schließen neu zu laden ist.
     */
    #[Locked]
    public bool $modal = false;

    /** @var list<array{type: string, text: string, message_id: ?int}> */
    public array $results = [];

    protected function ingestLocal(string $path, string $name, string $source): bool
    {
        try {
            $result = app(BeaImporter::class)->ingest($path, $name, $source);
        } catch (\Throwable $e) {
            $this->results[] = ['type' => 'danger', 'text' => "{$name}: ".$e->getMessage(), 'message_id' => null];

            return false;
        }

        $owner = app(AssignTypes::class)->resolve($this->modelType, $this->modelId);

        foreach ($result['imported'] as $message) {
            if ($owner) {
                $message->assignTo($owner);
            }
            $suggested = $message->messageFiles->where('suggested', true)->count();
            $this->results[] = [
                'type' => 'success',
                'text' => "{$name}: beA {$message->bea_id} eingelesen (AZ ".($message->aktenzeichen ?? '–')."), {$suggested} Datei(en) zur Übernahme vorgeschlagen",
                'message_id' => $message->id,
            ];
        }
        foreach ($result['skipped'] as $key) {
            $this->results[] = ['type' => 'secondary', 'text' => "{$name}: beA {$key} war bereits eingelesen", 'message_id' => null];
        }
        foreach (array_unique($result['warnings']) as $warning) {
            $this->results[] = ['type' => 'warning', 'text' => "{$name}: {$warning}", 'message_id' => null];
        }

        if ($result['imported'] !== []) {
            $this->dispatch('bea-ingested', count: count($result['imported']));
        }

        return true;
    }
}
