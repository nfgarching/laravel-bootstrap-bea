<?php

namespace Nfgarching\Bea\Livewire;

use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Nfgarching\Bea\Livewire\Concerns\IngestsArchives;

/**
 * Einlesen per Upload: ZIPs, wie sie aus dem beA heruntergeladen werden.
 */
class Upload extends Component
{
    use IngestsArchives, WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $zips = [];

    public function mount(?string $modelType = null, ?int $modelId = null, bool $modal = false): void
    {
        $this->modelType = $modelType;
        $this->modelId = $modelId;
        $this->modal = $modal;
    }

    public function rules(): array
    {
        return ['zips.*' => ['required', 'file', 'mimes:zip', 'max:'.config('bea.upload.max_kb', 204800)]];
    }

    public function ingest(): void
    {
        $this->validate();
        $this->results = [];

        foreach ($this->zips as $zip) {
            $this->ingestLocal($zip->getRealPath(), $zip->getClientOriginalName(), 'upload');
        }

        $this->zips = [];
    }

    public function render(): View
    {
        return view('bea::livewire.upload');
    }
}
