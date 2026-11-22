<?php

namespace Nfgarching\Bea\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Nfgarching\Bea\Data\BeaFile;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Services\AssignTypes;
use Nfgarching\Bea\Services\BeaImporter;

/**
 * Übernahme einer eingelesenen Nachricht: Dateien auswählen (vorbelegt mit
 * den Vorschlägen, Vorschau aus der Original-ZIP im Fullscreen-Modal) und an das Files-Package
 * übergeben.
 *
 * Aufruf: Route bea.select (?model_type=…&model_id=…&data=…) – im
 * generischen iframe-Modal (<x-button.dispatch frame …>, core), als Popup
 * oder Tab. Akte, Namen … wählt das Package nicht aus: die Anwendung ordnet
 * sie im Listener auf BeaFilesAccepted zu. Mitgegeben wird nur, was der
 * Aufruf kennt – model_type/model_id als Ziel (z. B. ein Dokument), die
 * Schlüssel aus data (bea.assign.context) als Kontext.
 */
#[Layout('layout::popup')]
class Select extends Component
{
    #[Locked]
    public int $messageId;

    #[Locked]
    public string $modelType = '';

    #[Locked]
    public int $modelId = 0;

    /** @var array<string, mixed> */
    #[Locked]
    public array $data = [];

    /** @var list<int|string> BeaFile::$id aus data.files */
    public array $selected = [];

    public string $subject = '';

    public ?string $error = null;

    public function mount(BeaMessage $message): void
    {
        $this->messageId = $message->id;

        if (request()->filled('model_type')) {
            $this->modelType = request()->string('model_type')->toString();
            $this->modelId = request()->integer('model_id');
        }
        $raw = request()->input('data', []);
        $this->data = is_string($raw) ? (json_decode($raw, true) ?? []) : (array) $raw;

        $this->subject = (string) $message->subject;
        $this->selected = $message->suggestedFiles()
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
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
     * Ziel aus dem Aufruf (z. B. „Dokument: Ladung …“), sonst die bestehende
     * Zuordnung der Nachricht.
     */
    #[Computed]
    public function assignedLabel(): ?string
    {
        $types = app(AssignTypes::class);
        if ($this->modelId && $types->resolve($this->modelType, $this->modelId)) {
            return $types->label($this->modelType, $this->modelId);
        }

        $message = $this->message;

        return $message->isAssigned() ? $types->label($message->model_type, $message->model_id) : null;
    }

    /**
     * Kopfanzeige „Typ #ID“: Ziel aus dem Aufruf, sonst die bestehende
     * Zuordnung der Nachricht, sonst „neu: …“ (bea.assign.new).
     */
    #[Computed]
    public function contextLabel(): ?string
    {
        $types = app(AssignTypes::class);
        if ($this->modelId && $types->resolve($this->modelType, $this->modelId)) {
            return $types->idLabel($this->modelType, $this->modelId);
        }

        $message = $this->message;

        return ($message->isAssigned() ? $types->idLabel($message->model_type, $message->model_id) : null)
            ?? $types->newLabel();
    }

    public function removeAssignment(): void
    {
        $this->message->unassign();
        unset($this->message, $this->assignedLabel, $this->contextLabel);
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

    public function accept(BeaImporter $importer): void
    {
        $this->error = null;
        $types = app(AssignTypes::class);
        $target = $this->modelId ? $types->resolve($this->modelType, $this->modelId) : null;

        try {
            $result = $importer->accept(
                $this->message,
                array_map('intval', $this->selected),
                $types->contextModels($this->data),
                trim($this->subject) ?: null,
                $target,
            );
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            unset($this->message);

            return;
        }

        $text = count($result['files']).' Datei(en) übernommen.';
        if ($result['failed'] !== []) {
            $text .= ' Nicht übernommen: '.implode(', ', $result['failed']).'.';
        }
        session()->flash('message', $text);

        // Wie Nfgarching\Files\Livewire\Import: das angelegte Modell (Route aus dem
        // Listener) anzeigen – aus einem Modell heraus aufgerufen im aufrufenden
        // Fenster, sonst in einem neuen Fenster (finishPopup() im Layout layout::popup).
        if ($result['route']) {
            $this->js('finishPopup('.Js::from($result['route']).', '.Js::from($target !== null).')');

            return;
        }

        // Ohne Route: Hauptfenster bzw. Opener neu laden, ohne Opener (Tab) auf die Nachricht
        $this->js('reloadOpener('.Js::from(route('bea.show', ['message' => $this->messageId, 'tab' => 'dateien'])).')');
    }

    public function render(): View
    {
        return view('bea::livewire.select');
    }
}
