<div>
    @php
        $message = $this->message;
        $selectable = $this->rows->filter->isSelectable();
    @endphp

    <div class="frame-hide"><br></div>

    @if ($error)
        <div class="alert alert-danger py-2">{{ $error }}</div>
    @endif

    <x-card>
        <x-slot name="headerLeft">
            <h5 class="card-title mb-0">
                <i class="bi bi-check2-square"></i> {{ $message->subject ?: '(ohne Betreff)' }}
                @if ($message->eeb_requested)
                    <span class="badge text-bg-danger" title="Elektronisches Empfangsbekenntnis angefordert">eEB</span>
                @endif
                @if ($message->isOutgoing())
                    <span class="badge text-bg-info">gesendet</span>
                @endif
            </h5>
        </x-slot>

        <x-slot name="headerRight">
            <span class="text-nowrap">@include('bea::parts.badges', ['item' => $message])</span>
            <small class="text-muted ms-2">{{ $this->contextLabel ?? 'ohne Zuordnung' }}</small>
        </x-slot>

        {{-- Kopfdaten --}}
        <div class="row g-1 small mb-2">
            <div class="col-md-6"><span class="text-muted">{{ $message->isOutgoing() ? 'Empfänger' : 'Absender' }}:</span>
                {{ ($message->isOutgoing() ? $message->recipient_name : $message->sender_name) ?? '–' }}</div>
            <div class="col-md-6"><span class="text-muted">{{ $message->datedLabel() }}:</span>
                {{ $message->dated_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '–' }}</div>
            <div class="col-md-6"><span class="text-muted">Unser Aktenzeichen:</span> {{ $message->aktenzeichen ?? '–' }}</div>
            <div class="col-md-6"><span class="text-muted">Aktenzeichen {{ $message->isOutgoing() ? 'Empfänger' : 'Absender' }}:</span>
                {{ $message->aktenzeichen_absender ?? '–' }}</div>
            <div class="col-12">
                <span class="text-muted">Zuordnung:</span>
                @if ($this->assignedLabel)
                    <span class="badge text-bg-success d-inline-flex align-items-center gap-1 fw-normal">
                        {{ $this->assignedLabel }}
                        @if (! $modelId && $message->isAssigned())
                            <button type="button" class="btn-close btn-close-white" style="font-size: .55rem"
                                wire:click="removeAssignment" title="Zuordnung entfernen"></button>
                        @endif
                    </span>
                @else
                    <span class="text-muted">neu – die Anwendung legt beim Übernehmen das Ziel an und ordnet es selbst zu (z. B. Akte und Name über das Aktenzeichen).</span>
                @endif
            </div>
        </div>

        @if ($message->integrity !== 'complete' || ! in_array($message->vhn_signature, ['valid', 'signature_ok'], true))
            <div class="alert alert-warning py-2 small">
                Die Prüfung der Nachricht ist nicht ohne Beanstandung.
                <a href="{{ route('bea.show', ['message' => $message, 'tab' => 'pruefung']) }}" target="_blank">Prüfung ansehen</a>
            </div>
        @endif

        {{-- Dateien; Vorschau im Fullscreen-Modal (bea::parts.file-modal) --}}
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="small fw-semibold"><i class="bi bi-paperclip"></i> Dateien übernehmen</span>
                <div class="btn-group btn-group-sm ms-auto">
                    <button type="button" class="btn btn-outline-secondary" wire:click="selectSuggested">Vorschläge</button>
                    <button type="button" class="btn btn-outline-secondary" wire:click="selectNone">keine</button>
                </div>
            </div>
            <div class="list-group small">
                @forelse ($this->rows as $row)
                    <div wire:key="bea-select-file-{{ $row->id }}" @class([
                        'list-group-item d-flex align-items-center gap-2 px-2',
                        'text-muted' => $row->isAccepted(),
                    ])>
                        <div style="width: 1.25rem">
                            @if ($row->isAccepted())
                                <i class="bi bi-check-circle-fill text-success" title="bereits übernommen"></i>
                            @elseif ($row->isExcluded())
                                <i class="bi bi-dash-circle text-muted" title="wird nicht übernommen (bea.exclude_extensions)"></i>
                            @else
                                <input type="checkbox" class="form-check-input" value="{{ $row->id }}" wire:model.live="selected"
                                    id="row-{{ $row->id }}">
                            @endif
                        </div>
                        <label for="row-{{ $row->id }}" class="flex-grow-1 text-truncate mb-0" title="{{ $row->name }}">
                            {{ $row->label() }}
                            <div class="text-truncate opacity-75">
                                {{ $row->categoryLabel() ?? '–' }}
                                @if ($row->suggested)
                                    <i class="bi bi-lightbulb text-warning" title="zur Übernahme vorgeschlagen"></i>
                                @endif
                                · {{ Number::fileSize((int) $row->size) }}
                                @if ($row->signed)
                                    · <span title="Signatur {{ $row->signature_name }} bleibt in der ZIP. Die Datei wird im Original übernommen und gegen Bearbeitung gesperrt."><i class="bi bi-patch-check"></i> signiert</span>
                                @endif
                                @if ($row->vhn_covered === false)
                                    · <span class="text-warning" title="Keine passende Prüfsumme im Herkunftsnachweis">nicht im VHN</span>
                                @endif
                            </div>
                        </label>
                        @include('bea::parts.file-link', ['message' => $message, 'row' => $row])
                    </div>
                @empty
                    <div class="list-group-item text-center text-muted py-3">Die Nachricht enthält keine Anhänge.</div>
                @endforelse
            </div>
            <div class="form-text">
                Signaturdateien (.p7s) und technische Dateien (XJustiz, Herkunftsnachweis, Exportansicht) bleiben in der
                Original-ZIP. Nicht gewählte Dateien lassen sich später nachholen.
            </div>
        </div>

        <x-slot name="footer">
            <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                <input type="text" class="form-control form-control-sm flex-grow-1" style="min-width: 15rem"
                    wire:model="subject" placeholder="Betreff für die Übergabe an Dokumente" title="Betreff für die Übergabe an Dokumente">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('bea.show', $message) }}" data-frame-close>Abbrechen</a>
                <button type="button" class="btn btn-sm btn-primary text-nowrap" wire:click="accept" wire:loading.attr="disabled"
                    wire:target="accept">
                    <span wire:loading.remove wire:target="accept"><i class="bi bi-check2-square me-1"></i> Übernehmen
                        ({{ count($selected) }})</span>
                    <span wire:loading wire:target="accept"><span class="spinner-border spinner-border-sm"></span> Dateien werden geprüft und übergeben…</span>
                </button>
            </div>
            @if ($selectable->isEmpty())
                <div class="form-text">Alle Anhänge sind bereits übernommen – „Übernehmen“ übergibt nur noch die Auswahl.</div>
            @endif
        </x-slot>
    </x-card>

    @include('bea::parts.file-modal')
</div>
