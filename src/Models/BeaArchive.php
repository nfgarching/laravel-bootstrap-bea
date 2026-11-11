<?php

namespace Nfgarching\Bea\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eingelesene beA-ZIP im Original auf der Disk „beas“. sha256 ist die
 * Prüfsumme beim Einlesen; jede spätere Prüfung vergleicht dagegen.
 */
class BeaArchive extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @return HasMany<BeaMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(BeaMessage::class);
    }
}
