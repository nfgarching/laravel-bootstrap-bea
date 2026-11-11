<?php

namespace Nfgarching\Bea\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ergebnis einer Prüfung (beim Einlesen, vor der Übernahme, von Hand).
 * result: archive (sha256 erwartet/gefunden), files (je Datei), vhn (Signatur,
 * Zertifikat), integrity (VHN-Prüfsummen), warnings.
 */
class BeaVerification extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'archive_intact' => 'boolean',
            'files_intact' => 'boolean',
            'result' => 'array',
        ];
    }

    /** @return BelongsTo<BeaMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(BeaMessage::class, 'bea_message_id');
    }

    /**
     * Alles in Ordnung: ZIP und Dateien unverändert, Prüfsummen vollständig,
     * Signatur des Herkunftsnachweises nicht ungültig.
     */
    public function passed(): bool
    {
        return $this->archive_intact !== false
            && $this->files_intact !== false
            && $this->integrity === 'complete'
            && in_array($this->vhn_signature, ['valid', 'signature_ok'], true);
    }
}
