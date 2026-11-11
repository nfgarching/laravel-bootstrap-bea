<div>
    @php
        $message = $this->message;
        $selectable = $this->rows->filter->isSelectable();
    @endphp

    @if ($error)
        <div class="alert alert-danger py-2">{{ $error }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold">{{ $message->subject ?: '(ohne Betreff)' }}</span>
            @if ($message->eeb_requested)
                <span class="badge text-bg-danger" title="Elektronisches Empfangsbekenntnis angefordert">eEB</span>
            @endif
            <span class="ms-auto text-nowrap">@include('bea::parts.badges', ['item' => $message])</span>
        </div>
        <div class="card-body small">
            <div class="row g-2">
                <div class="col-md-6"><span class="text-muted">Absender:</span> {{ $message->sender_name ?? '–' }}</div>
                <div class="col-md-6"><span class="text-muted">Eingang:</span> {{ $message->received_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '–' }}</div>
                <div class="col-md-6"><span class="text-muted">Unser Aktenzeichen:</span> {{ $message->aktenzeichen ?? '–' }}</div>
                <div class="col-md-6"><span class="text-muted">Aktenzeichen Absender:</span> {{ $message->aktenzeichen_absender ?? '–' }}</div>
            </div>
            @if ($message->integrity !== 'complete' || ! in_array($message->vhn_signature, ['valid', 'signature_ok'], true))
                <div class="alert alert-warning py-2 mt-3 mb-0">
                    Die Prüfung der Nachricht ist nicht ohne Beanstandung.
                    <a href="{{ route('bea.show', ['message' => $message, 'tab' => 'pruefung']) }}">Prüfung ansehen</a>
                </div>
            @endif
        </div>
    </div>

    {{-- Zuordnung --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-link-45deg"></i> Zuordnung</div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-2">
                @foreach ($assigned as $index => $entry)
                    <span class="badge text-bg-success d-inline-flex align-items-center gap-1 fs-6 fw-normal">
                        @if ($index === 0)
                            <i class="bi bi-star-fill" title="Hauptzuordnung"></i>
                        @endif
                        {{ $entry['label'] }}
                        <button type="button" class="btn-close btn-close-white" style="font-size: .6rem"
                            wire:click="removeAssignment({{ $entry['id'] }})" title="Zuordnung entfernen"></button>
                    </span>
                @endforeach
                @foreach ($pending as $index => $entry)
                    <span class="badge text-bg-primary d-inline-flex align-items-center gap-1 fs-6 fw-normal">
                        @if ($assigned->isEmpty() && $index === 0)
                            <i class="bi bi-star-fill" title="Hauptzuordnung"></i>
                        @endif
                        {{ $entry['label'] }}
                        <button type="button" class="btn-close btn-close-white" style="font-size: .6rem"
                            wire:click="removePending({{ $index }})" title="Vorschlag entfernen"></button>
                    </span>
                @endforeach
                @if ($assigned->isEmpty() && empty($pending))
                    <span class="text-muted small">Noch nicht zugeordnet. Ohne Zuordnung bleiben die übernommenen Dateien bei der beA-Nachricht und werden erst mit der ersten Zuordnung übergeben.</span>
                @endif
            </div>

            @if (empty($this->types))
                <div class="text-muted small">
                    Zum Zuordnen die Modelle der Anwendung (Akte, Namen …) in <code>config/bea.php</code> unter <code>assign.types</code> eintragen.
                </div>
            @else
                <div class="row g-2">
                    @foreach ($this->types as $key => $type)
                        <div class="col-md-6" wire:key="type-{{ $key }}">
                            <label class="form-label small mb-1">{{ $type['label'] }} suchen</label>
                            <input type="search" class="form-control form-control-sm" wire:model.live.debounce.300ms="search.{{ $key }}"
                                placeholder="mindestens 2 Zeichen …">
                            @if (! empty($this->matches[$key]))
                                <div class="list-group mt-1">
                                    @foreach ($this->matches[$key] as $match)
                                        <button type="button" class="list-group-item list-group-item-action py-1 small"
                                            wire:click="addAssignment(@js((string) $key), @js($match['id']))">
                                            <i class="bi bi-plus-lg"></i> {{ $match['label'] }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="form-text">Die erste Zuordnung (Stern) ist die Hauptzuordnung: an sie werden die Dateien übergeben.</div>
            @endif
        </div>
    </div>

    {{-- Dateien --}}
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span><i class="bi bi-paperclip"></i> Dateien übernehmen</span>
            <div class="btn-group btn-group-sm ms-auto">
                <button type="button" class="btn btn-outline-secondary" wire:click="selectSuggested">Vorschläge</button>
                <button type="button" class="btn btn-outline-secondary" wire:click="selectNone">keine</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width: 2rem"></th>
                        <th>Datei</th>
                        <th>Art</th>
                        <th class="text-end">Größe</th>
                        <th>Hinweise</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        <tr wire:key="row-{{ $row->id }}" @class(['table-light text-muted' => $row->isAccepted()])>
                            <td>
                                @if ($row->isAccepted())
                                    <i class="bi bi-check-circle-fill text-success" title="bereits übernommen"></i>
                                @else
                                    <input type="checkbox" class="form-check-input" value="{{ $row->id }}" wire:model="selected" id="row-{{ $row->id }}">
                                @endif
                            </td>
                            <td class="small">
                                <label for="row-{{ $row->id }}" class="mb-0">{{ $row->label() }}</label>
                                @if ($row->display_name && $row->display_name !== $row->name)
                                    <div class="text-muted">{{ $row->name }}</div>
                                @endif
                            </td>
                            <td class="small text-nowrap">
                                {{ $row->categoryLabel() ?? '–' }}
                                @if ($row->suggested)
                                    <i class="bi bi-lightbulb text-warning" title="zur Übernahme vorgeschlagen"></i>
                                @endif
                            </td>
                            <td class="small text-end text-nowrap">{{ Number::fileSize((int) $row->size) }}</td>
                            <td class="small">
                                @if ($row->signed)
                                    <span class="badge text-bg-dark" title="Signatur {{ $row->signature_name }} bleibt in der ZIP. Die Datei wird im Original übernommen und gegen Bearbeitung gesperrt.">
                                        <i class="bi bi-patch-check"></i> signiert
                                    </span>
                                @endif
                                @if ($row->vhn_covered === false)
                                    <span class="badge text-bg-warning" title="Keine passende Prüfsumme im Herkunftsnachweis">nicht im VHN</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">Die Nachricht enthält keine Anhänge.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            Signaturdateien (.p7s) und technische Dateien (XJustiz, Herkunftsnachweis, Exportansicht) bleiben in der Original-ZIP
            und stehen nicht zur Auswahl. Nicht gewählte Dateien lassen sich später nachholen.
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <label class="form-label small mb-1" for="bea-subject">Betreff für die Übergabe an Dokumente</label>
            <input type="text" class="form-control form-control-sm mb-3" id="bea-subject" wire:model="subject">

            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary" wire:click="accept" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="accept"><i class="bi bi-check2-square me-1"></i> Übernehmen</span>
                    <span wire:loading wire:target="accept"><span class="spinner-border spinner-border-sm"></span> Dateien werden geprüft und übergeben…</span>
                </button>
                <a class="btn btn-outline-secondary" href="{{ route('bea.show', $message) }}">Zur Nachricht</a>
            </div>
            @if ($selectable->isEmpty())
                <div class="form-text">Alle Anhänge sind bereits übernommen – „Übernehmen“ speichert nur noch die Zuordnung.</div>
            @endif
        </div>
    </div>
</div>
