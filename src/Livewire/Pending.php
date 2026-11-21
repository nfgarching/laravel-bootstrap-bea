<?php

namespace Nfgarching\Bea\Livewire;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Nfgarching\Bea\Data\BeaFile;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Services\AssignTypes;
use Nfgarching\Bea\Services\BeaImporter;

/**
 * Noch nicht übernommene beA-Nachrichten (Status „eingelesen“) markieren und
 * in einem Schritt übernehmen – mit den vorgeschlagenen Dateien, wie
 * Nfgarching\Files\Livewire\Import für Scans.
 *
 * Aufruf: Route bea.pending (?model_type=…&model_id=…&data=…) – als Popup
 * oder im generischen iframe-Modal (<x-button.dispatch frame …>, core).
 *
 * Standard ist model_type App\Models\User ohne ID: Die Zuordnung der
 * Nachricht bleibt, wie sie ist (der User). Kommt der Aufruf aus einem Modell
 * (z. B. App\Models\Dokument), wird die Nachricht ihm zugeordnet und die
 * Dateien gehen an dieses Modell – sofern es in bea.assign.types
 * konfiguriert ist. Schlüssel aus data (bea.assign.context, z. B. die Akte)
 * gehen als Kontext an die Anwendung.
 */
#[Layout('layout::popup')]
class Pending extends Component
{
    use WithPagination;

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

    /**
     * Einzeln gewählte Dateien je Nachricht (BeaFile::$id als String).
     * Ohne Eintrag gelten die Vorschläge; ein Eintrag entsteht beim Aufklappen.
     *
     * @var array<int|string, list<string>>
     */
    public array $files = [];

    protected string $paginationTheme = 'bootstrap';

    /** @var list<string> */
    private const array SORTABLE = ['dated_at', 'subject', 'sender_name', 'aktenzeichen'];

    public function mount(): void
    {
        $this->modelType = (string) config('bea.default_model_type', 'App\\Models\\User');
        $this->applyContext([
            'model_type' => request()->input('model_type'),
            'model_id' => request()->input('model_id'),
            'data' => request()->input('data', []),
        ]);
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
        $sort = in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'dated_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        return BeaMessage::query()
            ->where('status', BeaMessage::STATUS_INGESTED)
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
     * @return Collection<int, BeaFile>
     */
    #[Computed]
    public function expandedFiles(): Collection
    {
        $message = $this->expanded ? BeaMessage::find($this->expanded) : null;
        if (! $message) {
            return collect();
        }

        return $message->attachmentFiles()
            ->sortBy([['suggested', 'desc'], ['name', 'asc']])
            ->values();
    }

    public function toggle(int $messageId): void
    {
        $this->expanded = $this->expanded === $messageId ? null : $messageId;
        unset($this->expandedFiles);

        if ($this->expanded && ! array_key_exists($messageId, $this->files)) {
            $this->files[$messageId] = $this->suggestedIds($messageId);
        }
    }

    /**
     * Dateiauswahl einer Nachricht geändert: die Nachricht wird mit ausgewählt,
     * sobald mindestens eine Datei gewählt ist.
     */
    public function updatedFiles(mixed $value, ?string $key = null): void
    {
        $messageId = (string) strtok((string) $key, '.');
        if ($messageId !== '' && ($this->files[$messageId] ?? []) !== []
            && ! in_array($messageId, $this->selectedMessages, true)) {
            $this->selectedMessages[] = $messageId;
        }
    }

    public function selectSuggested(int $messageId): void
    {
        $this->files[$messageId] = $this->suggestedIds($messageId);
    }

    public function selectAllFiles(int $messageId): void
    {
        $this->files[$messageId] = (BeaMessage::find($messageId)?->attachmentFiles() ?? collect())
            ->filter(fn (BeaFile $row) => $row->isSelectable())
            ->map(fn (BeaFile $row) => (string) $row->id)->values()->all();
        $this->updatedFiles(null, (string) $messageId);
    }

    public function selectNoFiles(int $messageId): void
    {
        $this->files[$messageId] = [];
    }

    /**
     * Zu übernehmende Dateien: die einzeln gewählten, sonst die Vorschläge.
     *
     * @return list<int>
     */
    public function fileIdsFor(BeaMessage $message): array
    {
        $ids = array_key_exists($message->id, $this->files)
            ? $this->files[$message->id]
            : $message->suggestedFiles()->pluck('id')->all();

        return array_values(array_map('intval', $ids));
    }

    /**
     * @return list<string>
     */
    private function suggestedIds(int $messageId): array
    {
        return (BeaMessage::find($messageId)?->suggestedFiles() ?? collect())
            ->map(fn (BeaFile $row) => (string) $row->id)->values()->all();
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

        $types = app(AssignTypes::class);
        $target = $this->modelId ? $types->resolve($this->modelType, $this->modelId) : null;
        $context = $this->contextModels();
        $messages = BeaMessage::query()
            ->whereKey(array_map('intval', $this->selectedMessages))
            ->where('status', BeaMessage::STATUS_INGESTED)
            ->get();

        $accepted = [];
        $routes = [];
        $errors = [];
        foreach ($messages as $message) {
            $fileIds = $this->fileIdsFor($message);

            try {
                $result = $importer->accept($message, $fileIds, $context, target: $target);
                $accepted[] = '#'.$message->id.' ('.count($result['files']).' Datei(en))';
                $routes[] = $result['route'];
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
        $this->files = [];
        unset($this->messages);

        if ($errors !== []) {
            $this->error = implode("\n", $errors);

            return;
        }

        // Genau eine Nachricht: das angelegte Modell anzeigen (Route aus dem Listener,
        // wie bea.select und files.import) – aus einem Modell heraus im aufrufenden
        // Fenster, sonst in einem neuen Fenster (finishPopup() im Layout layout::popup).
        if (count($routes) === 1 && $routes[0]) {
            $this->js('finishPopup('.Js::from($routes[0]).', '.Js::from($target !== null).')');

            return;
        }

        // Mehrere: Popup: aufrufendes Fenster neu laden, iframe-Modal: Hauptfenster neu laden;
        // ohne Opener (Tab) auf die Übersicht (reloadOpener() im Layout layout::popup).
        $this->js('reloadOpener('.Js::from(route('bea.index')).')');
    }

    /**
     * Kontext für die Anwendung: die in bea.assign.context genannten
     * Schlüssel aus data (z. B. Register_ID → Akte).
     *
     * @return list<Model>
     */
    private function contextModels(): array
    {
        return app(AssignTypes::class)->contextModels($this->data);
    }

    public function render(): View
    {
        return view('bea::livewire.pending');
    }
}
