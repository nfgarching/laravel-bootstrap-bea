<div>
    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span><i class="bi bi-envelope-paper"></i> beA-Nachrichten</span>
            <input type="search" class="form-control form-control-sm ms-auto" style="max-width: 260px"
                placeholder="Absender, Betreff, Aktenzeichen …" wire:model.live.debounce.400ms="search">
            @if (Route::has('bea.import'))
                @php
                    $context = array_filter(['model_type' => $model_type, 'model_id' => $model_id]);
                @endphp
                <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary" href="{{ route('bea.import', $context) }}" title="Aus dem beA-Eingang einlesen"><i class="bi bi-hdd-network"></i></a>
                    <a class="btn btn-outline-secondary" href="{{ route('bea.upload', $context) }}" title="beA-ZIP hochladen"><i class="bi bi-upload"></i></a>
                </div>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <tbody>
                    @forelse ($rows as $item)
                        <tr wire:key="bea-{{ $item->id }}">
                            <td class="text-nowrap small">{{ $item->received_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '–' }}</td>
                            <td class="small">
                                <a href="{{ route('bea.show', $item) }}" class="text-decoration-none">{{ $item->subject ?: '(ohne Betreff)' }}</a>
                                @if ($item->eeb_requested)
                                    <span class="badge text-bg-danger ms-1" title="Elektronisches Empfangsbekenntnis angefordert">eEB</span>
                                @endif
                                <div class="text-muted">{{ $item->sender_name }}@if ($item->aktenzeichen_absender) · {{ $item->aktenzeichen_absender }}@endif</div>
                            </td>
                            <td class="text-center small text-nowrap" title="übernommen / Dateien">
                                <i class="bi bi-paperclip"></i> {{ $item->accepted_count }} / {{ $item->attachments_count }}
                            </td>
                            <td class="text-nowrap">@include('bea::parts.badges', ['item' => $item])</td>
                            <td class="text-end">
                                @if (! $item->isAccepted())
                                    <a class="btn btn-sm btn-primary" href="{{ route('bea.select', $item) }}" title="Übernehmen"><i class="bi bi-check2-square"></i></a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="text-center text-muted py-3">Keine beA-Nachrichten.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())
            <div class="card-footer">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
