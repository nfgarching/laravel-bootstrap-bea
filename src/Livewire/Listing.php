<?php

namespace Nfgarching\Bea\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Models\BeaMessageFile;

/**
 * beA-Nachrichten eines Modells – zum Einbetten in Akten, Dokumente und Namen:
 *
 *     <livewire:bea.listing :model_type="$akte->getMorphClass()" :model_id="$akte->id" />
 *
 * Gefunden werden Nachrichten, die dem Modell zugeordnet sind, und solche,
 * aus denen eine Datei des Modells stammt (z. B. das Dokument zu einer
 * übernommenen Verfügung). Ohne Modell zeigt die Komponente alle Nachrichten.
 */
class Listing extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Locked]
    public ?string $model_type = null;

    #[Locked]
    public ?int $model_id = null;

    /**
     * Zusätzliche Besitzer als [model_type, model_id]-Paare.
     *
     * @var list<array{0: string, 1: int|string}>
     */
    #[Locked]
    public array $owners = [];

    public string $search = '';

    public int $perPage = 10;

    public function updatedSearch(): void
    {
        $this->resetPage('beaPage');
    }

    public function render(): View
    {
        $owners = $this->owners;
        if ($this->model_type && $this->model_id) {
            $owners[] = [$this->model_type, $this->model_id];
        }

        $rows = BeaMessage::query()
            ->withCount([
                'messageFiles as attachments_count' => fn ($q) => $q->where('role', BeaMessageFile::ROLE_ATTACHMENT),
                'messageFiles as accepted_count' => fn ($q) => $q->whereNotNull('accepted_at'),
            ])
            ->forOwners($owners)
            ->search($this->search)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($this->perPage, pageName: 'beaPage');

        return view('bea::livewire.listing', ['rows' => $rows]);
    }
}
