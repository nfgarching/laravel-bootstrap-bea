<?php

namespace Nfgarching\Bea\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Nfgarching\Files\Models\File;

/**
 * Eingelesene beA-Nachricht. Die vollständigen ausgelesenen Daten stehen in
 * data (siehe BeaMessageData::toArray()).
 *
 * status: ingested (eingelesen, noch nichts übernommen) | accepted (Auswahl
 * getroffen, Dateien an das Files-Package übergeben)
 */
class BeaMessage extends Model
{
    public const string STATUS_INGESTED = 'ingested';

    public const string STATUS_ACCEPTED = 'accepted';

    /** Empfangene Nachricht */
    public const string DIRECTION_IN = 'in';

    /** Gesendete Nachricht (Export aus dem Ordner „Gesendet“) */
    public const string DIRECTION_OUT = 'out';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Sortierbare Spalten der Liste (MainControllerTrait::getSortParameters()).
     *
     * @var list<string>
     */
    public $sortable = ['id', 'dated_at', 'received_at', 'sent_at', 'sender_name', 'subject', 'aktenzeichen', 'aktenzeichen_absender', 'status'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'dated_at' => 'datetime',
            'intermediary_at' => 'datetime',
            'accepted_at' => 'datetime',
            'secure_channel' => 'boolean',
            'eeb_requested' => 'boolean',
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<BeaArchive, $this> */
    public function archive(): BelongsTo
    {
        return $this->belongsTo(BeaArchive::class, 'bea_archive_id');
    }

    /** @return HasMany<BeaMessageFile, $this> */
    public function messageFiles(): HasMany
    {
        return $this->hasMany(BeaMessageFile::class);
    }

    /** @return HasMany<BeaAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(BeaAssignment::class)->orderBy('id');
    }

    /** @return HasMany<BeaVerification, $this> */
    public function verifications(): HasMany
    {
        return $this->hasMany(BeaVerification::class)->orderByDesc('id');
    }

    /** @return HasOne<BeaVerification, $this> */
    public function latestVerification(): HasOne
    {
        return $this->hasOne(BeaVerification::class)->latestOfMany();
    }

    /**
     * Übernommene Dateien im Files-Package.
     *
     * @return Collection<int, File>
     */
    public function acceptedFiles(): Collection
    {
        $ids = $this->messageFiles->pluck('file_id')->filter();

        return File::query()->whereKey($ids)->orderBy('id')->get();
    }

    /**
     * Zuordnung festhalten, z. B. zur Akte – im eigenen Listener auf
     * BeaMessageImported oder bei der Übernahme. Die erste Zuordnung ist die
     * Hauptzuordnung.
     */
    public function assignTo(Model $model): BeaAssignment
    {
        return $this->assignments()->firstOrCreate(
            ['assignable_type' => $model->getMorphClass(), 'assignable_id' => $model->getKey()],
            ['assigned_by' => auth()->id()],
        );
    }

    public function unassign(Model $model): void
    {
        $this->assignments()
            ->where('assignable_type', $model->getMorphClass())
            ->where('assignable_id', $model->getKey())
            ->delete();
    }

    public function primaryAssignment(): ?BeaAssignment
    {
        return $this->assignments->first();
    }

    public function isAssigned(): bool
    {
        return $this->assignments->isNotEmpty();
    }

    public function isOutgoing(): bool
    {
        return $this->direction === self::DIRECTION_OUT;
    }

    /**
     * Beschriftung des maßgeblichen Datums (dated_at): „Eingang“ bzw. „Gesendet“.
     */
    public function datedLabel(): string
    {
        return $this->isOutgoing() ? 'Gesendet' : 'Eingang';
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isVhnTrusted(): bool
    {
        return $this->vhn_signature === 'valid';
    }

    /**
     * Nachrichten, die einem der Besitzer zugeordnet sind – direkt oder über
     * eine übernommene Datei, die dem Besitzer gehört (z. B. ein Dokument).
     *
     * @param  Builder<BeaMessage>  $query
     * @param  list<array{0: string, 1: int|string}>  $owners  [model_type, model_id]-Paare
     */
    public function scopeForOwners(Builder $query, array $owners): void
    {
        if ($owners === []) {
            return;
        }

        $match = function (Builder $q, string $typeColumn, string $idColumn) use ($owners) {
            $q->where(function (Builder $w) use ($owners, $typeColumn, $idColumn) {
                foreach ($owners as [$type, $id]) {
                    $w->orWhere(fn (Builder $o) => $o->where($typeColumn, $type)->where($idColumn, $id));
                }
            });
        };

        $query->where(function (Builder $q) use ($match) {
            $q->whereHas('assignments', fn (Builder $a) => $match($a, 'assignable_type', 'assignable_id'))
                ->orWhereHas('messageFiles.file', fn (Builder $f) => $match($f, 'model_type', 'model_id'));
        });
    }

    /**
     * Suche in Aktenzeichen, Absender, Betreff und beA-ID.
     *
     * @param  Builder<BeaMessage>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }
        $like = '%'.$term.'%';

        $query->where(fn (Builder $w) => $w->where('aktenzeichen', 'like', $like)
            ->orWhere('aktenzeichen_absender', 'like', $like)
            ->orWhere('sender_name', 'like', $like)
            ->orWhere('subject', 'like', $like)
            ->orWhere('bea_id', 'like', $like));
    }
}
