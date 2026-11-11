<?php

namespace Nfgarching\Bea\Livewire;

use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Models\BeaMessageFile;
use Nfgarching\Bea\Services\AssignTypes;
use Nfgarching\Bea\Services\BeaImporter;

/**
 * Übernahme einer eingelesenen Nachricht: Dateien auswählen (vorbelegt mit
 * den Vorschlägen), Akte und Namen zuordnen, an das Files-Package übergeben.
 */
class Select extends Component
{
    #[Locked]
    public int $messageId;

    /** @var list<int|string> IDs aus bea_message_files */
    public array $selected = [];

    public string $subject = '';

    /** @var list<array{type: string, id: int|string, label: string}> noch nicht gespeicherte Zuordnungen */
    public array $pending = [];

    /** @var array<string, string> Suchbegriff je Typ */
    public array $search = [];

    public ?string $error = null;

    public function mount(int $messageId): void
    {
        $this->messageId = $messageId;
        $message = $this->message;

        $this->subject = (string) $message->subject;
        $this->selected = $message->messageFiles
            ->filter(fn (BeaMessageFile $row) => $row->isSelectable() && $row->suggested)
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

        // Vorschlag anhand des Aktenzeichens, sofern noch nichts zugeordnet ist
        if ($message->assignments->isEmpty()) {
            $this->pending = app(AssignTypes::class)->suggestionsFor($message);
        }
    }

    #[Computed]
    public function message(): BeaMessage
    {
        return BeaMessage::with(['messageFiles.file', 'assignments', 'archive'])->findOrFail($this->messageId);
    }

    /**
     * @return Collection<int, BeaMessageFile>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->message->messageFiles
            ->where('role', BeaMessageFile::ROLE_ATTACHMENT)
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

    public function removeAssignment(int $assignmentId): void
    {
        $this->message->assignments()->whereKey($assignmentId)->delete();
        unset($this->message);
    }

    public function selectSuggested(): void
    {
        $this->selected = $this->rows
            ->filter(fn (BeaMessageFile $row) => $row->isSelectable() && $row->suggested)
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

        return view('bea::livewire.select', [
            'assigned' => $this->message->assignments->map(fn ($a) => [
                'id' => $a->id,
                'label' => $types->label($a->assignable_type, $a->assignable_id),
            ]),
        ]);
    }
}
