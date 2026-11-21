<?php

namespace Nfgarching\Bea\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection as BaseCollection;
use Nfgarching\Bea\Data\BeaFile;
use Nfgarching\Bea\Data\BeaZip;
use Nfgarching\Core\Helpers\DateTime;
use Nfgarching\Files\Models\File;

/**
 * Eingelesene beA-Nachricht. Die vollständigen ausgelesenen Daten stehen in
 * data (siehe BeaMessageData::toArray()), die Dateien der ZIP in data.files
 * (messageFiles()). Übernommene Dateien sind Files der Nachricht (files()).
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

    /**
     * Original-ZIP (Spalten archive_*), null wenn keine abgelegt ist.
     *
     * @return Attribute<?BeaZip, never>
     */
    protected function archive(): Attribute
    {
        return Attribute::get(fn () => BeaZip::fromColumns($this->attributes));
    }

    /**
     * Übernommene Dateien im Files-Package – sie gehören immer der Nachricht.
     *
     * @return MorphMany<File, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'model')->orderBy('id');
    }

    /** @var BaseCollection<int, BeaFile>|null */
    private ?BaseCollection $messageFilesCache = null;

    /**
     * Jede Datei der Original-ZIP (data.files), mit dem File, falls übernommen.
     *
     * @return BaseCollection<int, BeaFile>
     */
    public function messageFiles(): BaseCollection
    {
        if ($this->messageFilesCache !== null) {
            return $this->messageFilesCache;
        }

        $rows = (array) ($this->data['files'] ?? []);
        $files = $rows === [] || ! $this->exists ? collect() : $this->files()->get();
        $byPath = $files->keyBy(fn (File $file) => (string) ($file->custom_properties['bea']['zip_path'] ?? ''));
        $byName = $files->keyBy(fn (File $file) => (string) ($file->custom_properties['bea']['name'] ?? ''));

        $list = [];
        foreach (array_values($rows) as $i => $row) {
            $row = (array) $row;
            $file = $byPath->get((string) ($row['zip_path'] ?? '')) ?? $byName->get((string) ($row['name'] ?? ''));
            $list[] = BeaFile::fromArray($i + 1, $row, $file);
        }

        return $this->messageFilesCache = collect($list);
    }

    /**
     * Anlagen (role attachment), wie sie in der Auswahl stehen.
     *
     * @return BaseCollection<int, BeaFile>
     */
    public function attachmentFiles(): BaseCollection
    {
        return $this->messageFiles()->filter(fn (BeaFile $f) => $f->isAttachment())->values();
    }

    /**
     * Noch nicht übernommene, vorgeschlagene Anlagen.
     *
     * @return BaseCollection<int, BeaFile>
     */
    public function suggestedFiles(): BaseCollection
    {
        return $this->messageFiles()->filter(fn (BeaFile $f) => $f->isSelectable() && $f->suggested)->values();
    }

    public function messageFile(int $id): ?BeaFile
    {
        return $this->messageFiles()->firstWhere('id', $id);
    }

    /** Nach Übernahme oder erneutem Einlesen neu aufbauen. */
    public function forgetMessageFiles(): static
    {
        $this->messageFilesCache = null;
        $this->unsetRelation('files');

        return $this;
    }

    public function refresh()
    {
        $this->messageFilesCache = null;

        return parent::refresh();
    }

    /**
     * Zuordnung der Nachricht: beim Einlesen der User (bzw. das Modell, aus
     * dem heraus eingelesen wurde), nach der Übernahme z. B. das Dokument.
     *
     * @return MorphTo<Model, $this>
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
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
        return $this->files()->get();
    }

    /**
     * Zuordnung setzen (ersetzt die bisherige), z. B. das Dokument nach der
     * Übernahme im Listener auf BeaFilesAccepted.
     */
    public function assignTo(Model $model): void
    {
        $this->update(['model_type' => $model->getMorphClass(), 'model_id' => $model->getKey()]);
        $this->setRelation('model', $model);
    }

    public function unassign(): void
    {
        $this->update(['model_type' => null, 'model_id' => null]);
        $this->unsetRelation('model');
    }

    /**
     * Morph-Typ, der nur den User bezeichnet (bea.default_model_type) – eine
     * solche Zuordnung gilt nicht als Zuordnung zu einem Modell der Anwendung.
     */
    public static function userModelType(): string
    {
        return (string) config('bea.default_model_type', 'App\\Models\\User');
    }

    /**
     * Einem Modell der Anwendung zugeordnet (nicht nur dem User): an dieses
     * Modell gehen die übernommenen Dateien.
     */
    public function isAssigned(): bool
    {
        return $this->model_type !== null && $this->model_id !== null && $this->model_type !== self::userModelType();
    }

    /**
     * @param  Builder<BeaMessage>  $query
     */
    public function scopeAssigned(Builder $query, bool $assigned = true): void
    {
        $assigned
            ? $query->whereNotNull('model_type')->whereNotNull('model_id')->where('model_type', '!=', self::userModelType())
            : $query->where(fn (Builder $q) => $q->whereNull('model_type')->orWhereNull('model_id')->orWhere('model_type', self::userModelType()));
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

    /**
     * Kurzdatum (dated_at) für den Listen-Button, wie Dokument::get_btn_date().
     */
    public function get_btn_date(): string
    {
        return DateTime::btndate($this->dated_at);
    }

    /**
     * Bootstrap-Icon (ohne „bi-“) für den Listen-Button: Richtung und Übernahme.
     */
    public function get_btn_icon(): string
    {
        return match (true) {
            $this->isOutgoing() => 'send',
            $this->isAccepted() => 'envelope-check',
            default => 'envelope',
        };
    }

    /**
     * Button-Farbe für die Liste: übernommen = success, zugeordnet = primary,
     * sonst outline-primary (wie Dokument: outline = noch ohne Zuordnung).
     */
    public function get_btn_type(): string
    {
        return match (true) {
            $this->isAccepted() => 'success',
            $this->isAssigned() => 'primary',
            default => 'outline-primary',
        };
    }

    public function isVhnTrusted(): bool
    {
        return $this->vhn_signature === 'valid';
    }

    /**
     * Nachrichten, die einem der Besitzer zugeordnet sind (bea_messages.model).
     *
     * @param  Builder<BeaMessage>  $query
     * @param  list<array{0: string, 1: int|string}>  $owners  [model_type, model_id]-Paare
     */
    public function scopeForOwners(Builder $query, array $owners): void
    {
        if ($owners === []) {
            return;
        }

        $query->where(function (Builder $w) use ($owners) {
            foreach ($owners as [$type, $id]) {
                $w->orWhere(fn (Builder $o) => $o->where('model_type', $type)->where('model_id', $id));
            }
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
