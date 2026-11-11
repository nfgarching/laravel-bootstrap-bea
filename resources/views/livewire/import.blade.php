<div>
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span><i class="bi bi-hdd-network"></i> Eingang <code>{{ $diskName }}/{{ $inboxPath }}</code></span>
            <button type="button" class="btn btn-sm btn-primary ms-auto" wire:click="ingest" wire:loading.attr="disabled"
                @disabled(empty($selectedFiles))>
                <span wire:loading.remove wire:target="ingest"><i class="bi bi-box-arrow-in-down me-1"></i> Einlesen ({{ count($selectedFiles) }})</span>
                <span wire:loading wire:target="ingest"><span class="spinner-border spinner-border-sm"></span> Bitte warten…</span>
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width: 2rem"><input type="checkbox" class="form-check-input" wire:model.live="selectAll"></th>
                        <th><a href="#" wire:click.prevent="sortBy('name')" class="text-decoration-none">Datei</a></th>
                        <th class="text-end"><a href="#" wire:click.prevent="sortBy('size')" class="text-decoration-none">Größe</a></th>
                        <th><a href="#" wire:click.prevent="sortBy('lastModified')" class="text-decoration-none">Geändert</a></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->files as $file)
                        <tr wire:key="zip-{{ md5($file['path']) }}">
                            <td><input type="checkbox" class="form-check-input" value="{{ $file['path'] }}" wire:model.live="selectedFiles"></td>
                            <td class="small">{{ $file['name'] }}</td>
                            <td class="small text-end text-nowrap">{{ Number::fileSize($file['size']) }}</td>
                            <td class="small text-nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp($file['modified'], config('app.timezone'))->format('d.m.Y H:i') }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="deleteFile(@js($file['path']))"
                                    wire:confirm="ZIP „{{ $file['name'] }}“ aus dem Eingang löschen?">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Keine ZIP-Dateien im Eingang.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            Es wird nur eingelesen: Die ZIP wandert unverändert ins Archiv der Disk, die Dateien werden erfasst und geprüft.
            Welche Dateien übernommen werden, entscheiden Sie im nächsten Schritt.
        </div>
    </div>

    @include('bea::livewire.results')
</div>
