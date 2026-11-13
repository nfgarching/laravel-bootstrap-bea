<?php

namespace Nfgarching\Bea\Data;

use DateTimeImmutable;

/**
 * Ausgelesene beA-Nachricht (ein Ordner im heruntergeladenen ZIP).
 *
 * Quellen in absteigender Verlässlichkeit: xjustiz_nachricht.xml (XJustiz),
 * vhn.xml (Vertrauenswürdiger Herkunftsnachweis), <ID>.xml (OSCI-Umschlag),
 * <ID>_export.html (beA-Ansicht, liefert Eingangszeitpunkte).
 */
final class BeaMessageData
{
    public const string DIRECTION_IN = 'in';

    public const string DIRECTION_OUT = 'out';

    /**
     * @param  array<string, mixed>  $sender  name, safe_id, street, house_number, postal_code, city, state, country, organisation, role, display
     * @param  array<string, mixed>  $recipient  display, safe_id
     * @param  array<string, mixed>  $xjustiz  siehe XJustizParser
     * @param  array<string, mixed>  $vhn  present, version, secure_channel, role, hashes, producer
     * @param  list<array<string, mixed>>  $attachments  name, path, size, sha256, display_name, document_class, vhn_covered, signature_of
     * @param  array<string, ?string>  $metaFiles  Rolle => relativer Pfad
     * @param  array<string, string>  $exportFields  alle Felder der Export-Ansicht (Beschriftung => Wert)
     * @param  list<array<string, string>>  $journal  Nachrichtenjournal
     * @param  array<string, mixed>  $integrity  vhn_hashes, matched, unmatched_hashes, uncovered_attachments, complete
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $directory,
        public readonly ?string $beaId,
        public readonly ?string $osciId,
        public readonly ?string $subject,
        public readonly array $sender,
        public readonly array $recipient,
        public readonly ?string $aktenzeichen,
        public readonly ?string $aktenzeichenAbsender,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly ?DateTimeImmutable $receivedAt,
        public readonly ?DateTimeImmutable $intermediaryAt,
        public readonly ?bool $secureChannel,
        public readonly bool $eebRequested,
        public readonly array $xjustiz,
        public readonly array $vhn,
        public readonly array $attachments,
        public readonly array $metaFiles,
        public readonly array $exportFields,
        public readonly array $journal,
        public readonly array $integrity,
        public readonly array $warnings = [],
        public readonly string $direction = self::DIRECTION_IN,
    ) {}

    public function isOutgoing(): bool
    {
        return $this->direction === self::DIRECTION_OUT;
    }

    /**
     * Maßgebliches Datum: Eingang im Postfach, bei gesendeten Nachrichten der Versand.
     */
    public function datedAt(): ?DateTimeImmutable
    {
        return $this->isOutgoing()
            ? ($this->sentAt ?? $this->receivedAt)
            : ($this->receivedAt ?? $this->sentAt);
    }

    /**
     * Eindeutiger Schlüssel gegen Doppelimporte.
     */
    public function key(): ?string
    {
        return $this->beaId ?? $this->osciId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $date = fn (?DateTimeImmutable $d) => $d?->format(DATE_ATOM);

        return [
            'bea_id' => $this->beaId,
            'osci_id' => $this->osciId,
            'subject' => $this->subject,
            'sender' => $this->sender,
            'recipient' => $this->recipient,
            'direction' => $this->direction,
            'aktenzeichen' => $this->aktenzeichen,
            'aktenzeichen_absender' => $this->aktenzeichenAbsender,
            'sent_at' => $date($this->sentAt),
            'received_at' => $date($this->receivedAt),
            'intermediary_at' => $date($this->intermediaryAt),
            'secure_channel' => $this->secureChannel,
            'eeb_requested' => $this->eebRequested,
            'xjustiz' => $this->xjustiz,
            'vhn' => $this->vhn,
            'attachments' => $this->attachments,
            'meta_files' => $this->metaFiles,
            'export_fields' => $this->exportFields,
            'journal' => $this->journal,
            'integrity' => $this->integrity,
            'warnings' => $this->warnings,
        ];
    }
}
