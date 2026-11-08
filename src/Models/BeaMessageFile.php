<?php

namespace Nfgarching\Bea\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nfgarching\Files\Models\File;

/**
 * Datei einer beA-Nachricht. role: attachment | signature | report | export
 */
class BeaMessageFile extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['vhn_covered' => 'boolean'];
    }

    /** @return BelongsTo<BeaMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(BeaMessage::class, 'bea_message_id');
    }

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
