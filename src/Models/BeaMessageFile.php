<?php

namespace Nfgarching\Bea\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nfgarching\Files\Models\File;

/**
 * Datei einer beA-Nachricht in der ZIP.
 *
 * role: attachment (übernehmbar) | signature (.p7s, bleibt in der ZIP) |
 *       meta (XJustiz, VHN, Export, Prüfprotokolle – bleibt in der ZIP)
 */
class BeaMessageFile extends Model
{
    public const string ROLE_ATTACHMENT = 'attachment';

    public const string ROLE_SIGNATURE = 'signature';

    public const string ROLE_META = 'meta';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'vhn_covered' => 'boolean',
            'suggested' => 'boolean',
            'signed' => 'boolean',
            'accepted_at' => 'datetime',
        ];
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

    public function isSelectable(): bool
    {
        return $this->role === self::ROLE_ATTACHMENT && ! $this->isAccepted();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
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
