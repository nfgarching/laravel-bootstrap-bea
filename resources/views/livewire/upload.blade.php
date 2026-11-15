<div>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-upload"></i> beA-Nachrichten hochladen</div>
        <div class="card-body">
            <form wire:submit="ingest" class="d-flex gap-2 align-items-start flex-wrap">
                <div class="flex-grow-1">
                    <input type="file" class="form-control" wire:model="zips" multiple accept=".zip,application/zip">
                    <div class="form-text">
                        ZIP-Dateien, wie sie aus dem beA heruntergeladen werden. Bereits eingelesene Nachrichten werden übersprungen.
                        Es wird nur eingelesen@unless ($modal) – die Übernahme der Dateien folgt im nächsten Schritt@endunless.
                    </div>
                    {{-- „zips“: Upload gescheitert (z.B. PHP upload_max_filesize/post_max_size überschritten), „zips.*“: Validierung je Datei --}}
                    @error('zips')
                        <div class="text-danger small">{{ $message }}</div>
                    @enderror
                    @error('zips.*')
                        <div class="text-danger small">{{ $message }}</div>
                    @enderror
                </div>
                {{-- Sperre während Upload/Einlesen am fieldset, nicht am Button: Livewire 4 stellt nach dem Laden den Attributwert
                     vom Ladebeginn wieder her – beim ersten Upload also das serverseitige disabled, der Button bliebe gesperrt. --}}
                <fieldset class="border-0 p-0 m-0" wire:loading.attr="disabled" wire:target="ingest,zips">
                    <button type="submit" class="btn btn-primary text-nowrap" @disabled(empty($zips))>
                        <span wire:loading.remove wire:target="ingest,zips"><i class="bi bi-box-arrow-in-down me-1"></i> Einlesen</span>
                        <span wire:loading wire:target="ingest,zips"><span class="spinner-border spinner-border-sm"></span> Bitte warten…</span>
                    </button>
                </fieldset>
            </form>
        </div>
    </div>

    @include('bea::livewire.results')
</div>
