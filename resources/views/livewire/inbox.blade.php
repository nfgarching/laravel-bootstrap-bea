<div>
    {{-- Import --}}
    <div class="card mb-3">
        <div class="card-header">beA-Nachrichten importieren</div>
        <div class="card-body">
            <form wire:submit="import" class="d-flex gap-2 align-items-start flex-wrap">
                <div class="flex-grow-1">
                    <input type="file" class="form-control" wire:model="zips" multiple accept=".zip,application/zip">
                    <div class="form-text">ZIP-Dateien, wie sie aus dem beA heruntergeladen werden. Bereits importierte Nachrichten werden übersprungen.</div>
                    {{-- „zips“: Upload gescheitert (z.B. PHP upload_max_filesize/post_max_size überschritten), „zips.*“: Validierung je Datei --}}
                    @error('zips')
                        <div class="text-danger small">{{ $message }}</div>
                    @enderror
                    @error('zips.*')
                        <div class="text-danger small">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-primary text-nowrap" wire:loading.attr="disabled" @disabled(empty($zips))>
                    <span wire:loading.remove wire:target="import,zips"><i class="bi bi-box-arrow-in-down me-1"></i> Importieren</span>
                    <span wire:loading wire:target="import,zips"><span class="spinner-border spinner-border-sm"></span> Bitte warten…</span>
                </button>
            </form>

            @foreach ($messages as $message)
                <div class="alert alert-{{ $message['type'] }} py-1 px-2 mt-2 mb-0 small">{{ $message['text'] }}</div>
            @endforeach
        </div>
    </div>

    {{-- Liste --}}
    <div class="card">
        <div class="card-header d-flex gap-2 align-items-center">
            <input type="search" class="form-control form-control-sm" style="max-width: 320px"
                placeholder="Aktenzeichen, Absender, Betreff …" wire:model.live.debounce.400ms="search">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="bea-unassigned" wire:model.live="onlyUnassigned">
                <label class="form-check-label small" for="bea-unassigned">nur nicht zugeordnete</label>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Eingang</th>
                        <th>Absender</th>
                        <th>Betreff</th>
                        <th>Unser AZ</th>
                        <th>AZ Absender</th>
                        <th class="text-center"><i class="bi bi-paperclip"></i></th>
                        <th>Prüfung</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->list as $item)
                        <tr wire:key="bea-{{ $item->id }}">
                            <td class="text-nowrap small">{{ $item->received_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '–' }}</td>
                            <td class="small">{{ $item->sender_name }}</td>
                            <td class="small">
                                {{ $item->subject }}
                                @if ($item->eeb_requested)
                                    <span class="badge text-bg-danger ms-1" title="Elektronisches Empfangsbekenntnis angefordert">eEB</span>
                                @endif
                            </td>
                            <td class="small text-nowrap">
                                {{ $item->aktenzeichen ?? '–' }}
                                @if ($item->isAssigned())
                                    <i class="bi bi-link-45deg text-success" title="zugeordnet"></i>
                                @endif
                            </td>
                            <td class="small">{{ $item->aktenzeichen_absender }}</td>
                            <td class="text-center small">{{ $item->attachments_count }}</td>
                            <td class="text-nowrap">
                                @php
                                    $vhn = match ($item->vhn_signature) {
                                        'valid' => ['success', 'VHN gültig'],
                                        'signature_ok' => ['info', 'VHN-Signatur ok'],
                                        'invalid' => ['danger', 'VHN ungültig'],
                                        'missing' => ['secondary', 'kein VHN'],
                                        default => ['light', 'VHN '.$item->vhn_signature],
                                    };
                                    $hash = match ($item->integrity) {
                                        'complete' => ['success', 'Prüfsummen ok'],
                                        'incomplete' => ['danger', 'Prüfsummen abweichend'],
                                        default => ['secondary', 'ohne Prüfsummen'],
                                    };
                                @endphp
                                <span class="badge text-bg-{{ $vhn[0] }}">{{ $vhn[1] }}</span>
                                <span class="badge text-bg-{{ $hash[0] }}">{{ $hash[1] }}</span>
                                @if ($item->secure_channel)
                                    <span class="badge text-bg-light border" title="Sicherer Übermittlungsweg">SÜW</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Noch keine beA-Nachrichten importiert.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $this->list->links() }}</div>
    </div>
</div>
