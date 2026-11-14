<?php

namespace Nfgarching\Bea\Events;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Files\Models\File;

/**
 * Wird je neu eingelesener beA-Nachricht ausgelöst – noch bevor Dateien
 * übernommen sind. Die Nachricht ist dann dem User zugeordnet, der sie
 * eingelesen hat (bea_messages.model). Ein Listener im eigenen Projekt kann
 * sie stattdessen einem Modell zuordnen ($event->message->assignTo($model));
 * an dieses gehen später die übernommenen Dateien.
 *
 * Dateien gibt es zu diesem Zeitpunkt nicht: sie entstehen erst bei der
 * Übernahme (Event BeaFilesAccepted).
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
     *             Übernommene Dateien liefert BeaFilesAccepted.
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
