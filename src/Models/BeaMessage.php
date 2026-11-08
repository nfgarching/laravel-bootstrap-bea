<?php

namespace Nfgarching\Bea\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Nfgarching\Files\Models\File;

/**
 * Importierte beA-Nachricht. Die vollständigen ausgelesenen Daten stehen in
 * data (siehe BeaMessageData::toArray()).
 */
class BeaMessage extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'intermediary_at' => 'datetime',
            'secure_channel' => 'boolean',
            'eeb_requested' => 'boolean',
            'data' => 'array',
        ];
    }

    /** @return HasMany<BeaMessageFile, $this> */
    public function messageFiles(): HasMany
    {
        return $this->hasMany(BeaMessageFile::class);
    }

    /** @return BelongsTo<File, $this> */
    public function zipFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'zip_file_id');
    }

    /**
     * Alle File-Datensätze dieser Nachricht (Anhänge, Prüfprotokoll, Export).
     *
     * @return MorphMany<File, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'model');
    }

    /**
     * Die eigentlichen Anhänge (ohne Prüfprotokoll und Exportansicht).
     *
     * @return Collection<int, File>
     */
    public function attachments(): Collection
    {
        $ids = $this->messageFiles->whereIn('role', ['attachment', 'signature'])->pluck('file_id')->filter();

        return File::query()->whereKey($ids)->get();
    }

    /** @return MorphTo<Model, $this> */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Zuordnung durch den eigenen Listener festhalten, z. B. zur Akte.
     */
    public function assignTo(Model $model): void
    {
        $this->assignable()->associate($model)->save();
    }

    public function isAssigned(): bool
    {
        return $this->assignable_type !== null;
    }

    public function isVhnTrusted(): bool
    {
        return $this->vhn_signature === 'valid';
    }
}
