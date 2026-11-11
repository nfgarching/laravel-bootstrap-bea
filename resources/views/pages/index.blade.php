<x-app-layout>
    <x-slot name="header">
        @include('bea::parts.header', ['title' => 'beA-Nachrichten'])
    </x-slot>

    @if (session('message'))
        <div class="alert alert-info py-2">{{ session('message') }}</div>
    @endif

    @if ($pending > 0 && ! $request->filled('status'))
        <div class="alert alert-warning py-2 d-flex align-items-center gap-2">
            <i class="bi bi-inbox"></i>
            <span>{{ $pending }} eingelesene Nachricht(en) warten auf die Übernahme.</span>
            <a class="btn btn-sm btn-outline-dark ms-auto" href="{{ route('bea.index', ['status' => 'ingested']) }}">anzeigen</a>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('bea.index') }}" class="row g-2 align-items-center">
                <div class="col-12 col-md-4">
                    <input type="search" name="search" class="form-control form-control-sm" value="{{ $request->search }}"
                        placeholder="Aktenzeichen, Absender, Betreff, beA-ID …">
                </div>
                <div class="col-6 col-md-auto">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Status: alle</option>
                        <option value="ingested" @selected($request->status === 'ingested')>eingelesen</option>
                        <option value="accepted" @selected($request->status === 'accepted')>übernommen</option>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <select name="assigned" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Zuordnung: alle</option>
                        <option value="1" @selected($request->assigned === '1')>zugeordnet</option>
                        <option value="0" @selected($request->assigned === '0')>nicht zugeordnet</option>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <select name="check" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Prüfung: alle</option>
                        <option value="ok" @selected($request->check === 'ok')>ohne Beanstandung</option>
                        <option value="problem" @selected($request->check === 'problem')>mit Beanstandung</option>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <select name="jahr" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Jahr: alle</option>
                        @foreach ($jahre as $jahr)
                            <option value="{{ $jahr }}" @selected((int) $request->jahr === $jahr)>{{ $jahr }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('bea.index') }}" title="Filter zurücksetzen"><i class="bi bi-x-lg"></i></a>
                </div>
            </form>
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
                        <th class="text-center" title="übernommen / Dateien"><i class="bi bi-paperclip"></i></th>
                        <th>Prüfung</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($messages as $item)
                        <tr>
                            <td class="text-nowrap small">{{ $item->received_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '–' }}</td>
                            <td class="small">{{ $item->sender_name }}</td>
                            <td class="small">
                                <a href="{{ route('bea.show', $item) }}" class="text-decoration-none">{{ $item->subject ?: '(ohne Betreff)' }}</a>
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
                            <td class="text-center small text-nowrap">{{ $item->accepted_count }} / {{ $item->attachments_count }}</td>
                            <td class="text-nowrap">
                                @include('bea::parts.badges', ['item' => $item, 'verification' => $item->latestVerification])
                            </td>
                            <td class="text-end text-nowrap">
                                @if (! $item->isAccepted())
                                    <a class="btn btn-sm btn-primary" href="{{ route('bea.select', $item) }}">
                                        <i class="bi bi-check2-square"></i> Übernehmen
                                    </a>
                                @else
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('bea.show', $item) }}"><i class="bi bi-info-square"></i></a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Keine beA-Nachrichten gefunden.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $messages->links() }}</div>
    </div>
</x-app-layout>
