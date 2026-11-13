<?php

namespace Nfgarching\Bea\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nfgarching\Bea\Models\BeaMessage;

/**
 * Modelle der Anwendung, denen eine beA-Nachricht zugeordnet werden kann
 * (config bea.assign.types). Nur hier eingetragene Modelle werden aus
 * Benutzereingaben aufgelöst.
 */
class AssignTypes
{
    /**
     * @return array<string, array{label: string, model: class-string<Model>, search: list<string>, display: string, match: ?string}>
     */
    public function all(): array
    {
        $types = [];
        foreach ((array) config('bea.assign.types', []) as $key => $type) {
            $model = $type['model'] ?? null;
            if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
                continue;
            }
            $display = (string) ($type['display'] ?? (new $model)->getKeyName());
            $types[(string) $key] = [
                'label' => (string) ($type['label'] ?? class_basename($model)),
                'model' => $model,
                'search' => array_values((array) ($type['search'] ?? [$display])),
                'display' => $display,
                'match' => $type['match'] ?? null,
            ];
        }

        return $types;
    }

    /**
     * Schlüssel des Typs zu einer Klasse oder einem Morph-Alias.
     */
    public function keyFor(?string $modelType): ?string
    {
        if (! $modelType) {
            return null;
        }
        $class = Relation::getMorphedModel($modelType) ?? $modelType;

        foreach ($this->all() as $key => $type) {
            if ($type['model'] === $class || (new $type['model'])->getMorphClass() === $modelType) {
                return $key;
            }
        }

        return null;
    }

    public function find(string $key, int|string $id): ?Model
    {
        $type = $this->all()[$key] ?? null;

        return $type ? $type['model']::query()->find($id) : null;
    }

    /**
     * Modell eines konfigurierten Typs über eine beliebige Spalte, z. B. die
     * Akte über ihre Legacy-ID aus dem Aufrufkontext (bea.assign.context).
     * Leere Spalte = Primärschlüssel.
     */
    public function findBy(string $key, string $column, mixed $value): ?Model
    {
        $type = $this->all()[$key] ?? null;
        if (! $type || blank($value)) {
            return null;
        }

        return $column === ''
            ? $type['model']::query()->find($value)
            : $type['model']::query()->where($column, $value)->first();
    }

    /**
     * Modell aus model_type/model_id (z. B. Aufruf aus einer Akte heraus).
     */
    public function resolve(?string $modelType, int|string|null $id): ?Model
    {
        $key = $this->keyFor($modelType);

        return $key !== null && $id ? $this->find($key, $id) : null;
    }

    /**
     * @return list<array{id: int|string, label: string}>
     */
    public function search(string $key, string $term, int $limit = 8): array
    {
        $type = $this->all()[$key] ?? null;
        $term = trim($term);
        if (! $type || mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $type['model']::query()
            ->where(function ($query) use ($type, $like) {
                foreach ($type['search'] as $column) {
                    $query->orWhere($column, 'like', $like);
                }
            })
            ->limit($limit)
            ->get()
            ->map(fn (Model $model) => ['id' => $model->getKey(), 'label' => $this->display($model, $type['display'])])
            ->all();
    }

    /**
     * Anzeige einer gespeicherten Zuordnung, auch wenn der Typ nicht (mehr) konfiguriert ist.
     */
    public function label(string $modelType, int|string $id): string
    {
        $key = $this->keyFor($modelType);
        $type = $key !== null ? $this->all()[$key] : null;
        $model = $type ? $type['model']::query()->find($id) : null;

        if ($type && $model) {
            return $type['label'].': '.$this->display($model, $type['display']);
        }

        return ($type['label'] ?? class_basename($modelType)).' #'.$id;
    }

    /**
     * Vorschläge anhand des Aktenzeichens der Nachricht (Typen mit „match“).
     *
     * @return list<array{type: string, id: int|string, label: string}>
     */
    public function suggestionsFor(BeaMessage $message): array
    {
        $aktenzeichen = trim((string) $message->aktenzeichen);
        if ($aktenzeichen === '') {
            return [];
        }

        $candidates = [$aktenzeichen];
        $pattern = config('bea.assign.aktenzeichen_pattern');
        if ($pattern && @preg_match($pattern, $aktenzeichen, $match) === 1 && $match[0] !== $aktenzeichen) {
            array_unshift($candidates, $match[0]);
        }

        $suggestions = [];
        foreach ($this->all() as $key => $type) {
            if (! $type['match']) {
                continue;
            }
            $model = $type['model']::query()->whereIn($type['match'], $candidates)->first();
            if ($model) {
                $suggestions[] = [
                    'type' => $key,
                    'id' => $model->getKey(),
                    'label' => $type['label'].': '.$this->display($model, $type['display']),
                ];
            }
        }

        return $suggestions;
    }

    private function display(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        return $value !== null && $value !== '' ? (string) $value : '#'.$model->getKey();
    }
}
