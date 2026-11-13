{{-- Anlagen einer Nachricht mit Vorschau aus der Original-ZIP (bea.pending): $message --}}
<div class="row g-2">
    <div class="col-lg-4">
        <div class="list-group list-group-flush small">
            @forelse ($this->expandedFiles as $row)
                @php
                    $url = route('bea.file', ['message' => $message->id, 'messageFile' => $row->id]);
                    $previewable = $this->isPreviewable($row);
                @endphp
                <div wire:key="bea-pending-file-{{ $row->id }}" @class([
                    'list-group-item d-flex align-items-center gap-2 px-2',
                    'active' => $previewFile === $row->id,
                ])>
                    <i class="bi bi-{{ $row->suggested ? 'check2-square' : 'square' }}"
                        title="{{ $row->suggested ? 'wird übernommen (Vorschlag)' : 'nicht vorgeschlagen' }}"></i>
                    <div class="flex-grow-1 text-truncate" title="{{ $row->name }}">
                        {{ $row->label() }}
                        <div class="text-truncate opacity-75">
                            @if ($row->categoryLabel())
                                {{ $row->categoryLabel() }}
                            @endif
                            @if ($row->signed)
                                · <i class="bi bi-patch-check"></i> signiert
                            @endif
                            @if ($row->isAccepted())
                                · übernommen
                            @endif
                        </div>
                    </div>
                    {{-- Klick => Vorschau im Modal, SHIFT => Popup, CTRL => Tab --}}
                    <button type="button" @class([
                        'btn btn-sm',
                        'btn-light' => $previewFile === $row->id,
                        'btn-outline-secondary' => $previewFile !== $row->id,
                    ])
                        title="{{ $previewable ? 'Vorschau (SHIFT: Popup, CTRL: Tab)' : 'Öffnen (keine Vorschau möglich)' }}"
                        x-on:click="
                            const url = @js($url);
                            if ($event.ctrlKey || ! @js($previewable)) { window.open(url, '_blank'); }
                            else if ($event.shiftKey) {
                                window.openPopup ? openPopup(url, 'width=800,height=900') : window.open(url, 'beaPreview', 'width=800,height=900');
                            }
                            else { $wire.preview({{ $row->id }}); }
                        ">
                        <i class="bi bi-{{ $previewable ? 'eye' : 'box-arrow-up-right' }}"></i>
                    </button>
                </div>
            @empty
                <div class="text-muted">--- Keine Anlagen ---</div>
            @endforelse
        </div>
    </div>

    <div class="col-lg-8">
        @if ($previewFile)
            <div class="d-flex justify-content-end mb-1">
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="closePreview"
                    title="Vorschau schließen"><i class="bi bi-x-lg"></i></button>
            </div>
            <iframe wire:key="bea-preview-{{ $previewFile }}"
                src="{{ route('bea.file', ['message' => $message->id, 'messageFile' => $previewFile]) }}"
                class="w-100 border rounded" style="min-height: 65vh" title="Vorschau"></iframe>
        @else
            <div class="text-muted small p-3 border rounded text-center">
                <i class="bi bi-file-earmark-pdf fs-3 d-block mb-1"></i>
                Datei links wählen – SHIFT öffnet sie als Popup.
            </div>
        @endif
    </div>
</div>
