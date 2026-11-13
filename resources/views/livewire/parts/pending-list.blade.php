{{-- Liste der Komponente bea.pending (Modal und Popup) --}}
@php
    $types = app(\Nfgarching\Bea\Services\AssignTypes::class);
@endphp

@if ($modal && $this->contextLabel)
    <p class="small text-muted mb-2"><i class="bi bi-link-45deg"></i> Zuordnen zu: {{ $this->contextLabel }}</p>
@endif

@if ($error)
    <div class="alert alert-danger py-2 small" style="white-space: pre-line">{{ $error }}</div>
@endif

{{-- Toolbar --}}
<div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
    <input type="search" class="form-control form-control-sm" style="max-width: 18rem"
        wire:model.live.debounce.300ms="search" placeholder="Suche: Az., Absender, Betreff, beA-ID">

    <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            {{ $perPage }} pro Seite
        </button>
        <ul class="dropdown-menu">
            @foreach ([10, 25, 50, 100] as $n)
                <li>
                    <button class="dropdown-item @if ($perPage === $n) active @endif"
                        wire:click="setPerPage({{ $n }})">{{ $n }} pro Seite</button>
                </li>
            @endforeach
        </ul>
    </div>

    @if (count($selectedMessages) > 0)
        <span class="badge bg-primary">{{ count($selectedMessages) }} ausgewählt</span>
    @endif

    <small class="text-muted ms-auto">Übernommen werden die vorgeschlagenen Dateien – einzeln wählen über <i class="bi bi-ui-checks"></i></small>
</div>

@php
    $sortButton = function (string $field, string $label) use ($sort, $direction) {
        $icon = $sort === $field ? ' <i class="bi bi-caret-'.($direction === 'asc' ? 'up' : 'down').'-fill"></i>' : '';
        return '<button class="btn btn-link btn-sm p-0 text-body text-decoration-none fw-semibold" wire:click="sortBy(\''.$field.'\')">'.e($label).$icon.'</button>';
    };
@endphp

<table class="table table-sm table-hover table-striped mb-2 align-middle">
    <thead>
        <tr>
            <th style="width: 2rem">
                <input class="form-check-input" type="checkbox" wire:model.live="selectAll">
            </th>
            <th>{!! $sortButton('dated_at', 'Datum') !!}</th>
            <th>{!! $sortButton('sender_name', 'Absender') !!} / Empfänger</th>
            <th>{!! $sortButton('subject', 'Betreff') !!} / {!! $sortButton('aktenzeichen', 'Az.') !!}</th>
            <th>Prüfung</th>
            <th class="text-end">Dateien</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($this->messages as $item)
            <tr wire:key="bea-pending-{{ $item->id }}">
                <td class="text-nowrap">
                    <input class="form-check-input" type="checkbox" wire:model.live="selectedMessages"
                        value="{{ $item->id }}">
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-body"
                        wire:click="toggle({{ $item->id }})" title="Anlagen und Vorschau">
                        <i class="bi bi-chevron-{{ $expanded === $item->id ? 'down' : 'right' }}"></i>
                    </button>
                </td>
                <td class="text-nowrap">
                    <span class="badge bg-secondary" title="{{ $item->datedLabel() }}">{{ $item->dated_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '–' }}</span>
                    @if ($item->isOutgoing())
                        <span class="badge text-bg-info" title="Gesendete Nachricht – Datum ist der Versand"><i class="bi bi-send"></i> gesendet</span>
                    @endif
                    @if ($item->eeb_requested)
                        <span class="badge text-bg-warning" title="Elektronisches Empfangsbekenntnis angefordert">eEB</span>
                    @endif
                </td>
                <td>
                    {{ $item->sender_name ?? '–' }}
                    @if ($item->isOutgoing())
                        <div class="small text-muted"><i class="bi bi-arrow-right"></i> {{ $item->recipient_name ?? '–' }}</div>
                    @endif
                </td>
                <td>
                    {{ $item->subject ?: '(ohne Betreff)' }}
                    <div class="small text-muted">
                        @if ($item->aktenzeichen)
                            Unser Az. {{ $item->aktenzeichen }}
                        @endif
                        @if ($item->aktenzeichen_absender)
                            · Az. {{ $item->isOutgoing() ? 'Empfänger' : 'Absender' }} {{ $item->aktenzeichen_absender }}
                        @endif
                    </div>
                    @foreach ($item->assignments as $assignment)
                        <span class="badge text-bg-light border">
                            <i class="bi bi-link-45deg"></i>
                            {{ $types->label($assignment->assignable_type, $assignment->assignable_id) }}
                        </span>
                    @endforeach
                </td>
                <td>@include('bea::parts.badges', ['item' => $item])</td>
                <td class="text-end text-nowrap">
                    <span class="badge bg-primary" title="vorgeschlagen">{{ $item->suggested_count }}</span>
                    <span class="badge bg-secondary" title="Anlagen insgesamt">{{ $item->attachments_count }}</span>
                </td>
                <td class="text-nowrap" width="1%">
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary" href="{{ route('bea.show', $item) }}" target="_blank"
                            title="Details"><i class="bi bi-eye"></i></a>
                        <a class="btn btn-outline-secondary" href="{{ route('bea.select', $item) }}" target="_blank"
                            title="Dateien einzeln auswählen"><i class="bi bi-ui-checks"></i></a>
                    </div>
                </td>
            </tr>
            @if ($expanded === $item->id)
                <tr wire:key="bea-pending-files-{{ $item->id }}">
                    <td></td>
                    <td colspan="6">
                        @include('bea::livewire.parts.pending-files', ['message' => $item])
                    </td>
                </tr>
            @endif
        @empty
            <tr>
                <td colspan="7" class="text-muted">--- Keine nicht übernommenen Nachrichten ---</td>
            </tr>
        @endforelse
    </tbody>
</table>

{{ $this->messages->links() }}
