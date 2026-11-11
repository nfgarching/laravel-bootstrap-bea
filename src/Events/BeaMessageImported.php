<?php

namespace Nfgarching\Bea\Events;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Files\Models\File;

/**
 * Wird je neu eingelesener beA-Nachricht ausgelöst – noch bevor Dateien
 * übernommen sind. Der Listener im eigenen Projekt kann die Nachricht anhand
 * des Aktenzeichens vorab zuordnen; die Zuordnung erscheint dann bei der
 * Übernahme als Vorschlag:
 *
 *     $akte = Akte::where('aktenzeichen', $event->aktenzeichen)->first();
 *     $akte && $event->message->assignTo($akte);
 *
 * Dateien gibt es zu diesem Zeitpunkt nicht: sie entstehen erst bei der
 * Übernahme (Events NewFiles des Files-Packages und BeaFilesAccepted).
 * $data enthält alles, was ausgelesen wurde (siehe docs/Datenformat.md).
 */
class BeaMessageImported
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string|null  $aktenzeichen  Aktenzeichen des Empfängers (eigenes Aktenzeichen)
     * @param  string|null  $aktenzeichenAbsender  Aktenzeichen des Absenders (Gericht, Behörde, Kollege)
     * @param  array<string, mixed>  $data  vollständige Nachrichtendaten
     */
    public function __construct(
        public BeaMessage $message,
        public ?string $aktenzeichen,
        public ?string $aktenzeichenAbsender,
        public array $data,
    ) {}

    /**
     * @deprecated Beim Einlesen entstehen keine Dateien mehr – immer leer.
     *             Übernommene Dateien liefern NewFiles und BeaFilesAccepted.
     *
     * @var list<int>
     */
    public array $fileIds = [];

    /**
     * @deprecated siehe $fileIds – immer leer.
     *
     * @return Collection<int, File>
     */
    public function attachments(): Collection
    {
        return new Collection;
    }
}
