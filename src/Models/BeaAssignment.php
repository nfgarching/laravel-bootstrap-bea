<?php

namespace Nfgarching\Bea\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Zuordnung einer beA-Nachricht zu einem Modell der Anwendung (Akte, Name …).
 */
class BeaAssignment extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return BelongsTo<BeaMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(BeaMessage::class, 'bea_message_id');
    }

    /** @return MorphTo<Model, $this> */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
