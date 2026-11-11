<?php

namespace Nfgarching\Bea\Events;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Files\Models\File;

/**
 * Die Auswahl einer beA-Nachricht wurde übernommen: die gewählten Dateien
 * liegen im Files-Package, die Zuordnungen (Akte, Namen …) stehen fest.
 *
 * Für die Hauptzuordnung löst das Package zusätzlich NewFiles des
 * Files-Packages aus (Übergabe an Dokumente). Dieses Event trägt alle
 * Zuordnungen, auch die weiteren.
 */
class BeaFilesAccepted
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<int>  $fileIds  File-IDs der in diesem Schritt übernommenen Dateien
     * @param  list<array{type: string, id: int|string}>  $assignments  alle Zuordnungen, Hauptzuordnung zuerst
     */
    public function __construct(
        public BeaMessage $message,
        public array $fileIds,
        public array $assignments,
    ) {}

    /**
     * @return Collection<int, File>
     */
    public function files(): Collection
    {
        return File::query()->whereKey($this->fileIds)->orderBy('id')->get();
    }
}
