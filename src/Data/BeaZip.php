<?php

namespace Nfgarching\Bea\Data;

/**
 * Original-ZIP einer beA-Nachricht auf der Disk BEA_DISK (Spalten archive_*
 * in bea_messages). sha256 ist die Prüfsumme beim Einlesen; jede spätere
 * Prüfung vergleicht dagegen. Mehrere Nachrichten einer ZIP verweisen auf
 * dieselbe Datei.
 */
final class BeaZip
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $original_name,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $source = 'upload',
    ) {}

    /**
     * Spalten für bea_messages.
     *
     * @return array<string, string|int>
     */
    public function toColumns(): array
    {
        return [
            'archive_disk' => $this->disk,
            'archive_path' => $this->path,
            'archive_name' => $this->original_name,
            'archive_sha256' => $this->sha256,
            'archive_size' => $this->size,
            'archive_source' => $this->source,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes  Spalten einer bea_messages-Zeile
     */
    public static function fromColumns(array $attributes): ?self
    {
        if (blank($attributes['archive_path'] ?? null) || blank($attributes['archive_sha256'] ?? null)) {
            return null;
        }

        return new self(
            disk: (string) $attributes['archive_disk'],
            path: (string) $attributes['archive_path'],
            original_name: (string) ($attributes['archive_name'] ?? basename((string) $attributes['archive_path'])),
            sha256: (string) $attributes['archive_sha256'],
            size: (int) ($attributes['archive_size'] ?? 0),
            source: (string) ($attributes['archive_source'] ?? 'upload'),
        );
    }
}
