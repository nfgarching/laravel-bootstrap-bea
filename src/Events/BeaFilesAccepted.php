<?php

namespace Nfgarching\Bea\Events;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Files\Models\File;

/**
 * Die Auswahl einer beA-Nachricht wurde übernommen: die gewählten Dateien
 * liegen im Files-Package und gehören der Nachricht (model_type BeaMessage) –
 * sie werden nicht umgehängt. Die Anwendung erreicht sie über die Zuordnung
 * der Nachricht ($message->model, z. B. ein Dokument).
 *
 * $context trägt, was beim Übernehmen gewählt bzw. mitgegeben wurde (Akte,
 * Namen …); gespeichert wird es nicht. Legt der Listener ein Modell an
 * (z. B. das Dokument), trägt er es mit $message->assignTo() ein.
 *
 * $route setzt der Listener auf die Anzeige des angelegten Modells (wie
 * NewFiles im Files-Package); bea.select öffnet sie danach per finishPopup().
 * Bleibt sie leer, geht es zurück auf die Nachricht.
 */
class BeaFilesAccepted
{
    use Dispatchable, SerializesModels;

    /** Anzeige des Modells, das die Anwendung angelegt hat (z. B. das Dokument) */
    public ?string $route = null;

    /**
     * @param  list<int>  $fileIds  File-IDs der in diesem Schritt übernommenen Dateien
     * @param  list<array{type: string, id: int|string}>  $context  Akte, Namen … aus der Übernahme
     * @param  string|null  $subject  Betreff für die Übergabe (bea.select), sonst der der Nachricht
     */
    public function __construct(
        public BeaMessage $message,
        public array $fileIds,
        public array $context = [],
        public ?string $subject = null,
    ) {}

    /**
     * @return Collection<int, File>
     */
    public function files(): Collection
    {
        return File::query()->whereKey($this->fileIds)->orderBy('id')->get();
    }
}
