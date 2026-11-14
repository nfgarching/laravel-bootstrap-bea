<?php

namespace Nfgarching\Bea\Livewire;

use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Nfgarching\Bea\Data\BeaFile;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Services\AssignTypes;
use Nfgarching\Bea\Services\BeaImporter;

/**
 * Übernahme einer eingelesenen Nachricht: Dateien auswählen (vorbelegt mit
 * den Vorschlägen), Akte und Namen wählen, an das Files-Package übergeben.
 *
 * Akte, Namen … gehen nur als Kontext an die Anwendung (Event
 * BeaFilesAccepted); gespeichert wird allein die Zuordnung der Nachricht
 * (bea_messages.model), die die Anwendung beim Übernehmen setzt.
 */
class Select extends Component
{
    #[Locked]
    public int $messageId;

    /** @var list<int|string> BeaFile::$id aus data.files */
    public array $selected = [];

    public string $subject = '';

    /** @var list<array{type: string, id: int|string, label: string}> Kontext für die Übernahme (Akte, Namen …) */
    public array $pending = [];

    /** @var array<string, string> Suchbegriff je Typ */
    public array $search = [];

    public ?string $error = null;

    public function mount(int $messageId): void
    {
        $this->messageId = $messageId;
        $message = $this->message;

        $this->subject = (string) $message->subject;
        $this->selected = $message->suggestedFiles()
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

        // Vorschlag anhand des Aktenzeichens, sofern noch nichts zugeordnet ist
        if (! $message->isAssigned()) {
            $this->pending = app(AssignTypes::class)->suggestionsFor($message);
        }
    }

    #[Computed]
    public function message(): BeaMessage
    {
        return BeaMessage::findOrFail($this->messageId);
    }

    /**
     * @return Collection<int, BeaFile>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->message->attachmentFiles()
            ->sortBy([['suggested', 'desc'], ['name', 'asc']])
            ->values();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function types(): array
    {
        return app(AssignTypes::class)->all();
    }

    /**
     * @return array<string, list<array{id: int|string, label: string}>>
     */
    #[Computed]
    public function matches(): array
    {
        $types = app(AssignTypes::class);
        $matches = [];
        foreach ($this->search as $key => $term) {
            $matches[$key] = $types->search((string) $key, (string) $term);
        }

        return $matches;
    }

    public function addAssignment(string $type, int|string $id): void
    {
        $types = app(AssignTypes::class);
        $model = $types->find($type, $id);
        if (! $model) {
            return;
        }

        foreach ($this->pending as $entry) {
            if ($entry['type'] === $type && (string) $entry['id'] === (string) $id) {
                return;
            }
        }

        $this->pending[] = ['type' => $type, 'id' => $id, 'label' => $types->label($model->getMorphClass(), $model->getKey())];
        $this->search[$type] = '';
    }

    public function removePending(int $index): void
    {
        unset($this->pending[$index]);
        $this->pending = array_values($this->pending);
    }

    public function removeAssignment(): void
    {
        $this->message->unassign();
        unset($this->message);
    }

    public function selectSuggested(): void
    {
        $this->selected = $this->rows
            ->filter(fn (BeaFile $row) => $row->isSelectable() && $row->suggested)
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function selectNone(): void
    {
        $this->selected = [];
    }

    public function accept(BeaImporter $importer)
    {
        $this->error = null;
        $types = app(AssignTypes::class);

        $models = [];
        foreach ($this->pending as $entry) {
            if ($model = $types->find($entry['type'], $entry['id'])) {
                $models[] = $model;
            }
        }

        try {
            $result = $importer->accept(
                $this->message,
                array_map('intval', $this->selected),
                $models,
                trim($this->subject) ?: null,
            );
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            unset($this->message);

            return null;
        }

        $text = count($result['files']).' Datei(en) übernommen.';
        if ($result['failed'] !== []) {
            $text .= ' Nicht übernommen: '.implode(', ', $result['failed']).'.';
        }
        session()->flash('message', $text);

        return $this->redirect(route('bea.show', ['message' => $this->messageId, 'tab' => 'dateien']));
    }

    public function render(): View
    {
        $types = app(AssignTypes::class);

        $message = $this->message;

        return view('bea::livewire.select', [
            'assigned' => $message->isAssigned() ? $types->label($message->model_type, $message->model_id) : null,
        ]);
    }
}
