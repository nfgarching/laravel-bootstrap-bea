{{-- Anlagen einer Nachricht (bea.pending): $message. Auswahl je Datei in $files[message-id]
     (vorbelegt mit den Vorschlägen), Vorschau im Modal
     (bea::parts.file-link + bea::parts.file-modal in bea::livewire.pending) --}}
@php
    $chosen = $files[$message->id] ?? [];
@endphp
<div class="d-flex align-items-center gap-2 mb-1 small">
    <span class="fw-semibold"><i class="bi bi-paperclip"></i> Dateien übernehmen ({{ count($chosen) }})</span>
    <div class="btn-group btn-group-sm ms-auto">
        <button type="button" class="btn btn-outline-secondary" wire:click="selectSuggested({{ $message->id }})"
            title="Nur die vorgeschlagenen Dateien">Vorschläge</button>
        <button type="button" class="btn btn-outline-secondary" wire:click="selectAllFiles({{ $message->id }})"
            title="Alle übernehmbaren Dateien">alle</button>
        <button type="button" class="btn btn-outline-secondary" wire:click="selectNoFiles({{ $message->id }})"
            title="Keine Datei – die Nachricht wird ohne Dateien übernommen">keine</button>
    </div>
</div>
<div class="list-group list-group-flush small">
    @forelse ($this->expandedFiles as $row)
        @php $inputId = 'bea-pending-file-'.$message->id.'-'.$row->id; @endphp
        <div wire:key="bea-pending-file-{{ $row->id }}" @class([
            'list-group-item d-flex align-items-center gap-2 px-2',
            'text-muted' => ! $row->isSelectable(),
        ])>
            <div style="width: 1.25rem">
                @if ($row->isAccepted())
                    <i class="bi bi-check-circle-fill text-success" title="bereits übernommen"></i>
                @elseif ($row->isExcluded())
                    <i class="bi bi-dash-circle" title="wird nicht übernommen (bea.exclude_extensions)"></i>
                @else
                    <input type="checkbox" class="form-check-input" id="{{ $inputId }}" value="{{ $row->id }}"
                        wire:model.live="files.{{ $message->id }}">
                @endif
            </div>
            @include('bea::parts.file-link', ['message' => $message, 'row' => $row])
            <label for="{{ $inputId }}" class="flex-grow-1 text-truncate mb-0" title="{{ $row->name }}">
                {{ $row->label() }}
                <span class="text-muted">
                    @if ($row->categoryLabel())
                        · {{ $row->categoryLabel() }}
                    @endif
                    @if ($row->suggested)
                        <i class="bi bi-lightbulb text-warning" title="zur Übernahme vorgeschlagen"></i>
                    @endif
                    @if ($row->size)
                        · {{ Number::fileSize((int) $row->size) }}
                    @endif
                    @if ($row->signed)
                        · <span title="Signatur {{ $row->signature_name }} bleibt in der ZIP"><i class="bi bi-patch-check"></i> signiert</span>
                    @endif
                    @if ($row->vhn_covered === false)
                        · <span class="text-warning" title="Keine passende Prüfsumme im Herkunftsnachweis">nicht im VHN</span>
                    @endif
                    @if ($row->isAccepted())
                        · übernommen
                    @endif
                </span>
            </label>
        </div>
    @empty
        <div class="text-muted">--- Keine Anlagen ---</div>
    @endforelse
</div>
