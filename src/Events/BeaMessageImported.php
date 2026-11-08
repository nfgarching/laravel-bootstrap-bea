<?php

namespace Nfgarching\Bea\Events;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Files\Models\File;

/**
 * Wird je neu importierter beA-Nachricht ausgelöst.
 *
 * Der Listener im eigenen Projekt ordnet die Nachricht anhand des
 * Aktenzeichens zu, z. B.:
 *
 *     $akte = Akte::where('aktenzeichen', $event->aktenzeichen)->first();
 *     $event->message->assignTo($akte);
 *     $event->attachments()->each->update(['model_type' => $akte::class, 'model_id' => $akte->id]);
 *
 * $data enthält alles, was ausgelesen wurde (siehe docs/Datenformat.md).
 */
class BeaMessageImported
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string|null  $aktenzeichen  Aktenzeichen des Empfängers (eigenes Aktenzeichen)
     * @param  string|null  $aktenzeichenAbsender  Aktenzeichen des Absenders (Gericht, Behörde, Kollege)
     * @param  array<string, mixed>  $data  vollständige Nachrichtendaten
     * @param  list<int>  $fileIds  File-IDs der Anhänge (inkl. Signaturdateien)
     * @param  array<string, int|null>  $metaFileIds  report, export, zip
     */
    public function __construct(
        public BeaMessage $message,
        public ?string $aktenzeichen,
        public ?string $aktenzeichenAbsender,
        public array $data,
        public array $fileIds,
        public array $metaFileIds = [],
    ) {}

    /**
     * @return Collection<int, File>
     */
    public function attachments(): Collection
    {
        return File::query()->whereKey($this->fileIds)->orderBy('id')->get();
    }
}
