<?php

namespace Nfgarching\Bea\Livewire;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Models\BeaMessageFile;
use Nfgarching\Bea\Services\AssignTypes;
use Nfgarching\Bea\Services\BeaImporter;

/**
 * Noch nicht übernommene beA-Nachrichten (Status „eingelesen“) markieren und
 * in einem Schritt übernehmen – mit den vorgeschlagenen Dateien, wie
 * Nfgarching\Files\Livewire\Import für Scans.
 *
 * Zwei Aufrufwege, eine Komponente:
 *  - Modal: über die ModalRegistry in jedes Layout eingebunden
 *    (<livewire:bea.pending :modal="true" />), geöffnet per Event
 *    „openModalBeaPending“ mit data: {model_type, model_id, data}.
 *  - Popup: Route bea.pending (?model_type=…&model_id=…&data=…).
 *
 * Standard ist model_type App\Models\User ohne ID: Die Nachricht wird dann
 * keinem Modell zusätzlich zugeordnet. Kommt der Aufruf aus einem Modell
 * (z. B. App\Models\Dokument), wird die Nachricht ihm zugeordnet – sofern
 * es in bea.assign.types konfiguriert ist.
 */
#[Layout('layout::popup')]
class Pending extends Component
{
    use WithPagination;

    public const string MODAL_NAME = 'modalBeaPending';

    public bool $modal = false;

    /** Im Modal-Modus wird die Liste erst nach dem Öffnen abgefragt */
    public bool $open = false;

    public string $modalName = self::MODAL_NAME;

    public string $modelType = 'App\\Models\\User';

    public int $modelId = 0;

    /** @var array<string, mixed> */
    public array $data = [];

    public string $sort = 'dated_at';

    public string $direction = 'desc';

    public int $perPage = 10;

    public string $search = '';

    public bool $selectAll = false;

    /** @var array<int, string> IDs aus bea_messages */
    public array $selectedMessages = [];

    public ?string $error = null;

    /** Aufgeklappte Nachricht: zeigt ihre Anlagen */
    public ?int $expanded = null;

    /** Anlage (bea_message_files.id) in der Vorschau */
    public ?int $previewFile = null;

    protected string $paginationTheme = 'bootstrap';

    /** @var list<string> */
    private const array SORTABLE = ['dated_at', 'subject', 'sender_name', 'aktenzeichen'];

    public function mount(bool $modal = false): void
    {
        $this->modal = $modal;
        $this->modelType = (string) config('bea.default_model_type', 'App\\Models\\User');

        if ($modal) {
            return;
        }

        $this->open = true;
        $this->applyContext([
            'model_type' => request()->input('model_type'),
            'model_id' => request()->input('model_id'),
            'data' => request()->input('data', []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  {model_type, model_id, data}
     */
    #[On('openModalBeaPending')]
    public function openModal(array $data = []): void
    {
        $this->reset('selectedMessages', 'selectAll', 'error', 'search', 'expanded', 'previewFile');
        $this->modelType = (string) config('bea.default_model_type', 'App\\Models\\User');
        $this->modelId = 0;
        $this->data = [];
        $this->applyContext($data);
        $this->resetPage('pendingPage');

        $this->open = true;
        unset($this->messages);
        $this->dispatch('show-modal', modalName: $this->modalName);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function applyContext(array $context): void
    {
        if (filled($context['model_type'] ?? null)) {
            $this->modelType = (string) $context['model_type'];
            $this->modelId = (int) ($context['model_id'] ?? 0);
        }

        $raw = $context['data'] ?? [];
        $this->data = is_string($raw) ? (json_decode($raw, true) ?? []) : (array) $raw;
    }

    /**
     * @return LengthAwarePaginator<int, BeaMessage>
     */
    #[Computed]
    public function messages(): LengthAwarePaginator
    {
        if (! $this->open) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        $sort = in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'dated_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        return BeaMessage::query()
            ->where('status', BeaMessage::STATUS_INGESTED)
            ->with('assignments')
            ->withCount([
                'messageFiles as attachments_count' => fn ($q) => $q->where('role', BeaMessageFile::ROLE_ATTACHMENT),
                'messageFiles as suggested_count' => fn ($q) => $q
                    ->where('role', BeaMessageFile::ROLE_ATTACHMENT)
                    ->where('suggested', true)
                    ->whereNull('accepted_at'),
            ])
            ->search($this->search)
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage, pageName: 'pendingPage');
    }

    /**
     * Anzeige des Aufrufkontexts, z. B. „Dokument: Ladung …“.
     */
    #[Computed]
    public function contextLabel(): ?string
    {
        return $this->modelId ? app(AssignTypes::class)->label($this->modelType, $this->modelId) : null;
    }

    /**
     * Anlagen der aufgeklappten Nachricht, Vorschläge zuerst.
     *
     * @return Collection<int, BeaMessageFile>
     */
    #[Computed]
    public function expandedFiles(): Collection
    {
        if (! $this->expanded) {
            return new Collection;
        }

        return BeaMessageFile::query()
            ->where('bea_message_id', $this->expanded)
            ->where('role', BeaMessageFile::ROLE_ATTACHMENT)
            ->get()
            ->sortBy([['suggested', 'desc'], ['name', 'asc']])
            ->values();
    }

    public function toggle(int $messageId): void
    {
        $this->expanded = $this->expanded === $messageId ? null : $messageId;
        $this->previewFile = null;
        unset($this->expandedFiles);

        // Erste vorgeschlagene PDF gleich anzeigen
        if ($this->expanded) {
            $this->previewFile = $this->expandedFiles
                ->first(fn (BeaMessageFile $row) => $row->suggested && $this->isPreviewable($row))?->id;
        }
    }

    public function preview(int $fileId): void
    {
        $this->previewFile = $this->expandedFiles->contains('id', $fileId) ? $fileId : null;
    }

    public function closePreview(): void
    {
        $this->previewFile = null;
    }

    /**
     * Im Browser darstellbar (PDF, Bilder, Text) – sonst nur Download.
     */
    public function isPreviewable(BeaMessageFile $row): bool
    {
        return in_array(strtolower(pathinfo((string) $row->name, PATHINFO_EXTENSION)), ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'txt'], true);
    }

    public function updatedSelectAll(bool $value): void
    {
        $pageIds = collect($this->messages->items())->map(fn (BeaMessage $m) => (string) $m->id)->all();

        $this->selectedMessages = $value
            ? array_values(array_unique([...$this->selectedMessages, ...$pageIds]))
            : array_values(array_diff($this->selectedMessages, $pageIds));
    }

    public function updatedSearch(): void
    {
        $this->selectAll = false;
        $this->expanded = null;
        $this->previewFile = null;
        $this->resetPage('pendingPage');
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORTABLE, true)) {
            return;
        }

        if ($this->sort === $field) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $field;
            $this->direction = 'asc';
        }

        $this->selectAll = false;
        $this->resetPage('pendingPage');
    }

    public function setPerPage(int $n): void
    {
        $this->perPage = in_array($n, [10, 25, 50, 100], true) ? $n : 10;
        $this->selectAll = false;
        $this->resetPage('pendingPage');
    }

    public function importMessages(BeaImporter $importer): void
    {
        $this->error = null;
        if ($this->selectedMessages === []) {
            return;
        }

        $models = $this->contextModels();
        $messages = BeaMessage::query()
            ->whereKey(array_map('intval', $this->selectedMessages))
            ->where('status', BeaMessage::STATUS_INGESTED)
            ->with('messageFiles')
            ->get();

        $accepted = [];
        $errors = [];
        foreach ($messages as $message) {
            $fileIds = $message->messageFiles
                ->filter(fn (BeaMessageFile $row) => $row->isSelectable() && $row->suggested)
                ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            try {
                $result = $importer->accept($message, $fileIds, $models);
                $accepted[] = '#'.$message->id.' ('.count($result['files']).' Datei(en))';
                if ($result['failed'] !== []) {
                    $errors[] = ($message->bea_id ?? '#'.$message->id).': nicht übernommen: '.implode(', ', $result['failed']);
                }
            } catch (\Throwable $e) {
                $errors[] = ($message->bea_id ?? '#'.$message->id).': '.$e->getMessage();
            }
        }

        Log::info('beA übernommen '.implode(', ', $accepted).' für '.$this->modelType.' (ID: '.$this->modelId.')');

        $this->selectedMessages = [];
        $this->selectAll = false;
        $this->expanded = null;
        $this->previewFile = null;
        unset($this->messages);

        if ($errors !== []) {
            $this->error = implode("\n", $errors);

            return;
        }

        if ($this->modal) {
            $this->dispatch('hide-modal', modalName: $this->modalName);
            $this->js('window.location.reload()');

            return;
        }

        $fallback = json_encode(route('bea.index'));
        $this->js("if (window.opener && !window.opener.closed) {
                window.opener.location.reload();
                window.opener.focus();
            } else {
                window.open({$fallback});
            }
            window.close();");
    }

    /**
     * Modelle, denen die Nachrichten zugeordnet werden: das aufrufende Modell
     * und die in bea.assign.context genannten Schlüssel aus data
     * (z. B. Register_ID → Akte). Nicht konfigurierte Modelle (wie der
     * Standard App\Models\User) bleiben außen vor.
     *
     * @return list<Model>
     */
    private function contextModels(): array
    {
        $types = app(AssignTypes::class);
        $models = [];

        if ($this->modelId && ($model = $types->resolve($this->modelType, $this->modelId))) {
            $models[] = $model;
        }

        foreach ((array) config('bea.assign.context', []) as $key => $target) {
            $value = $this->data[$key] ?? null;
            if (blank($value) || ! is_array($target)) {
                continue;
            }
            $model = $types->findBy((string) ($target['type'] ?? ''), (string) ($target['column'] ?? ''), $value);
            if ($model && ! collect($models)->contains(fn (Model $m) => $m->is($model))) {
                $models[] = $model;
            }
        }

        return $models;
    }

    public function render(): View
    {
        return view('bea::livewire.pending');
    }
}
