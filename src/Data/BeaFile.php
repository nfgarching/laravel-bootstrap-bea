<?php

namespace Nfgarching\Bea\Data;

use Illuminate\Support\Carbon;
use Nfgarching\Files\Models\File;

/**
 * Eine Datei der beA-Nachricht in der Original-ZIP – ein Eintrag aus
 * bea_messages.data.files, keine eigene Tabelle.
 *
 * role: attachment (übernehmbar) | signature (.p7s, bleibt in der ZIP) |
 *       meta (XJustiz, VHN, Export, Prüfprotokolle, bea.exclude_extensions –
 *       bleibt in der ZIP)
 *
 * Übernommen ist eine Datei, wenn die Nachricht ein File mit dieser Datei
 * besitzt (custom_properties.bea.zip_path).
 */
final class BeaFile
{
    public const string ROLE_ATTACHMENT = 'attachment';

    public const string ROLE_SIGNATURE = 'signature';

    public const string ROLE_META = 'meta';

    /**
     * @param  int  $id  laufende Nummer in data.files (ab 1), Schlüssel für Auswahl und Vorschau
     */
    public function __construct(
        public readonly int $id,
        public readonly string $role,
        public readonly ?string $kind,
        public readonly string $name,
        public readonly string $zip_path,
        public readonly ?string $display_name = null,
        public readonly ?string $document_class = null,
        public readonly ?string $category = null,
        public readonly bool $suggested = false,
        public readonly bool $signed = false,
        public readonly ?string $signature_name = null,
        public readonly ?string $signature_of = null,
        public readonly ?string $sha256 = null,
        public readonly ?int $size = null,
        public readonly ?bool $vhn_covered = null,
        public readonly ?File $file = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row  Eintrag aus data.files
     */
    public static function fromArray(int $id, array $row, ?File $file = null): self
    {
        return new self(
            id: $id,
            role: (string) ($row['role'] ?? self::ROLE_META),
            kind: $row['kind'] ?? null,
            name: (string) ($row['name'] ?? ''),
            zip_path: (string) ($row['zip_path'] ?? ''),
            display_name: $row['display_name'] ?? null,
            document_class: $row['document_class'] ?? null,
            category: $row['category'] ?? null,
            suggested: (bool) ($row['suggested'] ?? false),
            signed: (bool) ($row['signed'] ?? false),
            signature_name: $row['signature_name'] ?? null,
            signature_of: $row['signature_of'] ?? null,
            sha256: $row['sha256'] ?? null,
            size: isset($row['size']) ? (int) $row['size'] : null,
            vhn_covered: isset($row['vhn_covered']) ? (bool) $row['vhn_covered'] : null,
            file: $file,
        );
    }

    public function isAttachment(): bool
    {
        return $this->role === self::ROLE_ATTACHMENT;
    }

    public function isSelectable(): bool
    {
        return $this->isAttachment() && ! $this->isAccepted() && ! $this->isExcluded();
    }

    public function isAccepted(): bool
    {
        return $this->file !== null;
    }

    /**
     * Endung, die nie übernommen wird (bea.exclude_extensions, z. B. xml) –
     * greift auch für Einträge, die vor dieser Regel eingelesen wurden.
     */
    public function isExcluded(): bool
    {
        $excluded = array_map('strtolower', (array) config('bea.exclude_extensions', ['xml']));

        return in_array(strtolower(pathinfo($this->name, PATHINFO_EXTENSION)), $excluded, true);
    }

    public function fileId(): ?int
    {
        return $this->file?->id;
    }

    public function acceptedAt(): ?Carbon
    {
        return $this->file?->created_at;
    }

    public function label(): string
    {
        return $this->display_name ?: $this->name;
    }

    public function categoryLabel(): ?string
    {
        return $this->category ? (config('bea.categories.'.$this->category) ?? $this->category) : null;
    }
}
