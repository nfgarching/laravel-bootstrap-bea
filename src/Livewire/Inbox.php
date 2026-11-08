<?php

namespace Nfgarching\Bea\Livewire;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Services\BeaImporter;

/**
 * beA-Eingang: ZIPs hochladen und importierte Nachrichten anzeigen.
 */
class Inbox extends Component
{
    use WithFileUploads, WithPagination;

    protected $paginationTheme = 'bootstrap';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $zips = [];

    public string $search = '';

    public bool $onlyUnassigned = false;

    /** @var list<array{type: string, text: string}> */
    public array $messages = [];

    public function rules(): array
    {
        return ['zips.*' => ['required', 'file', 'mimes:zip', 'max:'.config('bea.upload.max_kb', 204800)]];
    }

    public function import(BeaImporter $importer): void
    {
        $this->validate();
        $this->messages = [];

        foreach ($this->zips as $zip) {
            $name = $zip->getClientOriginalName();
            try {
                $result = $importer->import($zip->getRealPath(), $name);
                foreach ($result['imported'] as $message) {
                    $this->messages[] = ['type' => 'success', 'text' => "{$name}: beA {$message->bea_id} importiert (AZ ".($message->aktenzeichen ?? '–').')'];
                }
                foreach ($result['skipped'] as $key) {
                    $this->messages[] = ['type' => 'secondary', 'text' => "{$name}: beA {$key} war bereits importiert"];
                }
                foreach (array_unique($result['warnings']) as $warning) {
                    $this->messages[] = ['type' => 'warning', 'text' => "{$name}: {$warning}"];
                }
            } catch (\Throwable $e) {
                $this->messages[] = ['type' => 'danger', 'text' => "{$name}: ".$e->getMessage()];
            }
        }

        $this->zips = [];
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function list(): LengthAwarePaginator
    {
        return BeaMessage::query()
            ->withCount(['messageFiles as attachments_count' => fn ($q) => $q->whereIn('role', ['attachment', 'signature'])])
            ->when($this->onlyUnassigned, fn ($q) => $q->whereNull('assignable_type'))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($w) => $w->where('aktenzeichen', 'like', $term)
                    ->orWhere('aktenzeichen_absender', 'like', $term)
                    ->orWhere('sender_name', 'like', $term)
                    ->orWhere('subject', 'like', $term)
                    ->orWhere('bea_id', 'like', $term));
            })
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(25);
    }

    public function render(): View
    {
        return view('bea::livewire.inbox');
    }
}
