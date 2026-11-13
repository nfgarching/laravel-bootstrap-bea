<x-dynamic-component :component="$popup ? 'popup-layout' : 'app-layout'">
    <x-slot name="header">
        @include('bea::parts.header', ['title' => 'beA-Nachrichten', 'modal' => true, 'listRoute' => $listRoute])
    </x-slot>

    @if (session('message'))
        <div class="alert alert-info py-2">{{ session('message') }}</div>
    @endif

    @if ($pending > 0 && !$request->filled('status'))
        <div class="alert alert-warning py-2 d-flex align-items-center gap-2">
            <i class="bi bi-inbox"></i>
            <span>{{ $pending }} eingelesene Nachricht(en) warten auf die Übernahme.</span>
            <a class="btn btn-sm btn-outline-dark ms-auto"
                href="{{ route($listRoute, array_merge($request->except('page'), ['status' => 'ingested'])) }}">anzeigen</a>
        </div>
    @endif

    @php
        $activeFilters = [];
        if ($request->status) {
            $activeFilters['status'] = 'Status: ' . ($statusOptions[$request->status] ?? $request->status);
        }
        if ($request->filled('assigned')) {
            $activeFilters['assigned'] = 'Zuordnung: ' . ($assignedOptions[$request->assigned] ?? $request->assigned);
        }
        if ($request->check) {
            $activeFilters['check'] = 'Prüfung: ' . ($checkOptions[$request->check] ?? $request->check);
        }
        if ($request->boolean('eeb')) {
            $activeFilters['eeb'] = 'eEB angefordert';
        }
        if ($request->jahr) {
            $activeFilters['jahr'] = 'Jahr: ' . $request->jahr;
        }
        if (isset($directionOptions[$request->richtung])) {
            $activeFilters['richtung'] = $directionOptions[$request->richtung];
        }
        $activeFilterCount = count($activeFilters);

        $sortField = strtolower((string) $request->get('sort', 'dated_at'));
        $sortAsc = strtoupper((string) $request->get('direction', 'DESC')) === 'ASC';
        $sortFields = [
            'dated_at' => 'Datum',
            'sender_name' => 'Absender',
            'subject' => 'Betreff',
            'aktenzeichen' => 'Unser AZ',
            'aktenzeichen_absender' => 'AZ Gegenseite',
            'status' => 'Status',
            'id' => 'Einlesen',
        ];
        // Parameter, die Suche und Schnellfilter im Kopf nicht selbst senden
        $keepParams = ['assigned', 'eeb', 'jahr', 'richtung', 'per_page', 'sort', 'direction', 'model_type', 'model_id'];
    @endphp

    <x-card>

        <x-slot name="headerLeft">
            <x-table.filter :action="route($listRoute)">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    @if ($activeFilterCount)
                        <span class="badge bg-warning text-dark">{{ $activeFilterCount }}</span>
                    @endif
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#beaFilterModal">
                            <i class="bi bi-funnel{{ $activeFilterCount ? '-fill' : '' }}"></i> Filter
                        </a>
                    </li>
                    <x-dropdown.link :href="route(
                        $listRoute,
                        array_merge($request->except(['eeb', 'page']), $request->boolean('eeb') ? [] : ['eeb' => 1]),
                    )">
                        <i class="bi bi-{{ $request->boolean('eeb') ? 'check2-square' : 'square' }}"></i>
                        nur mit eEB
                    </x-dropdown.link>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li>
                        <h6 class="dropdown-header">Sortieren</h6>
                    </li>
                    @foreach ($sortFields as $field => $label)
                        @php $active = $sortField === $field; @endphp
                        <x-dropdown.link :href="route(
                            $listRoute,
                            array_merge($request->except(['sort', 'direction', 'page']), [
                                'sort' => $field,
                                'direction' => $active && $sortAsc ? 'desc' : 'asc',
                            ]),
                        )">
                            @if ($active)
                                <i class="bi bi-sort-{{ $sortAsc ? 'up' : 'down' }}"></i>
                            @else
                                <i class="bi bi-dash"></i>
                            @endif
                            {{ $label }}
                        </x-dropdown.link>
                    @endforeach
                </ul>
                @foreach ($keepParams as $param)
                    @if ($request->filled($param))
                        <input type="hidden" name="{{ $param }}" value="{{ $request->get($param) }}">
                    @endif
                @endforeach
                <x-table.filter.search field="search" table="Aktenzeichen, Absender, Betreff, beA-ID"
                    :oldval="$request->search" />
                <x-table.filter.dropdown field="status" :options="$statusOptions" :oldval="$request->status" label="Status" />
                <x-table.filter.dropdown field="check" :options="$checkOptions" :oldval="$request->check" label="Prüfung" />
            </x-table.filter>

            @if ($activeFilterCount)
                <div class="d-flex flex-wrap gap-1 mt-1">
                    @foreach ($activeFilters as $param => $label)
                        <a href="{{ route($listRoute, $request->except([$param, 'page'])) }}"
                            class="badge bg-primary text-decoration-none">
                            {{ $label }} <i class="bi bi-x"></i>
                        </a>
                    @endforeach
                    <a href="{{ route($listRoute, array_filter($request->only(['search', 'per_page', 'model_type', 'model_id']))) }}"
                        class="badge bg-danger text-decoration-none">
                        Alle Filter <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            @endif
        </x-slot>

        <x-slot name="headerRight">
            <div class="btn-group">
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal"
                    data-bs-target="#beaImportModal" data-bea-tab="import" title="beA-Eingang einlesen">
                    <i class="bi bi-hdd-network"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal"
                    data-bs-target="#beaImportModal" data-bea-tab="upload" title="ZIP hochladen">
                    <i class="bi bi-upload"></i>
                </button>
            </div>
        </x-slot>

        @php $token = 'bea' . rand(); @endphp
        <x-table :acc_id="$token" class="table-sm table-hover mb-0 align-middle">
            <x-slot:header>
                <x-table.head.sort field="id" />
                <x-table.head.sort field="dated_at" label="Datum" />
                <x-table.head.sort field="sender_name" label="Absender / Empfänger" />
                <x-table.head.sort field="subject" label="Betreff" width="40%" />
                <x-table.head.sort field="aktenzeichen" label="Unser AZ" />
                <x-table.head.sort field="aktenzeichen_absender" label="AZ Gegenseite" />
                <th class="text-center" title="übernommen / Dateien"><i class="bi bi-paperclip"></i></th>
                <th></th>
                <th></th>
            </x-slot:header>

            @forelse ($messages as $item)
                @php
                    $showParams = array_merge(['message' => $item->id], $request->except('page'));
                    $verification = $item->latestVerification;
                    $problem =
                        $item->integrity !== 'complete' ||
                        !in_array($item->vhn_signature, ['valid', 'signature_ok'], true) ||
                        ($verification &&
                            ($verification->archive_intact === false || $verification->files_intact === false));
                @endphp
                <x-table.row.line1 :acc_id="$token" :row_id="$item->id">
                    <td>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('bea.show', $showParams) }}">
                            <i class="bi bi-info-square"></i>
                        </a>
                    </td>
                    <td class="text-nowrap small" title="{{ $item->datedLabel() }}">
                        {{ $item->dated_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '–' }}
                        @if ($item->isOutgoing())
                            <span class="badge text-bg-info" title="gesendete Nachricht"><i class="bi bi-send"></i></span>
                        @endif
                    </td>
                    <td class="small">
                        @if ($item->isOutgoing())
                            {{-- Gesendet: der Absender ist die Kanzlei, interessant ist der Empfänger --}}
                            <span title="Empfänger (gesendet von {{ $item->sender_name }})">
                                <i class="bi bi-arrow-right text-info"></i> {{ $item->recipient_name ?? '–' }}
                            </span>
                        @else
                            {{ $item->sender_name }}
                        @endif
                    </td>
                    <td class="small">
                        <a href="{{ route('bea.show', $showParams) }}"
                            class="text-decoration-none">{{ $item->subject ?: '(ohne Betreff)' }}</a>
                        @if ($item->eeb_requested)
                            <span class="badge text-bg-danger ms-1"
                                title="Elektronisches Empfangsbekenntnis angefordert">eEB</span>
                        @endif
                        @if ($problem)
                            <i class="bi bi-exclamation-triangle-fill text-danger ms-1"
                                title="Prüfung mit Beanstandung – Einzelheiten in der zweiten Zeile"></i>
                        @endif
                    </td>
                    <td class="small text-nowrap">
                        {{ $item->aktenzeichen ?? '–' }}
                        @if ($item->isAssigned())
                            <i class="bi bi-link-45deg text-success" title="zugeordnet"></i>
                        @endif
                    </td>
                    <td class="small">{{ $item->aktenzeichen_absender }}</td>
                    <td class="text-center small text-nowrap">{{ $item->accepted_count }} /
                        {{ $item->attachments_count }}</td>
                    <td class="text-end text-nowrap">
                        @if (!$item->isAccepted())
                            <a class="btn btn-sm btn-primary" href="{{ route('bea.select', $item) }}">
                                <i class="bi bi-check2-square"></i> Übernehmen
                            </a>
                        @else
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('bea.show', $showParams) }}"><i
                                    class="bi bi-info-square"></i></a>
                        @endif
                    </td>
                </x-table.row.line1>

                <x-table.row.line2 :acc_id="$token" :row_id="$item->id">
                    <td colspan="7">
                        <div class="d-flex flex-wrap gap-2 align-items-center small">
                            <span class="text-muted">Prüfung:</span>
                            @include('bea::parts.badges', [
                                'item' => $item,
                                'verification' => $verification,
                            ])
                            @if ($verification)
                                <span class="text-muted">zuletzt geprüft
                                    {{ $verification->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                            @endif
                            <span class="text-muted ms-auto">beA-ID {{ $item->bea_id ?? '–' }}</span>
                            <a class="btn btn-sm btn-outline-secondary"
                                href="{{ route('bea.show', array_merge($showParams, ['tab' => 'pruefung'])) }}">
                                <i class="bi bi-shield-check"></i> Prüfverlauf
                            </a>
                        </div>
                    </td>
                </x-table.row.line2>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">Keine beA-Nachrichten gefunden.</td>
                </tr>
            @endforelse
        </x-table>

        <x-slot name="footer">
            @php $perPage = $messages->perPage(); @endphp
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">

                <div class="input-group input-group-sm w-auto">
                    <label class="input-group-text" for="beaPerPage">
                        {{ $messages->firstItem() ?? 0 }} - {{ $messages->lastItem() ?? 0 }} von
                        {{ $messages->total() }}
                    </label>
                    <select class="form-select" id="beaPerPage" onchange="window.location.href = this.value">
                        @foreach ([5, 10, 25, 50, 100] as $n)
                            <option
                                value="{{ route($listRoute, array_merge($request->except(['per_page', 'page']), ['per_page' => $n])) }}"
                                @selected($perPage === $n)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    {{ $messages->withQueryString()->links('pagination.links') }}
                </div>

            </div>
        </x-slot>

    </x-card>

    {{-- Einlese-Modal: Eingang der Disk oder Upload. Es wird nur eingelesen (kein Dokument,
         kein Übernehmen); wurde etwas eingelesen, lädt die Liste beim Schließen neu. --}}
    <div class="modal fade" id="beaImportModal" tabindex="-1" x-data="{ ingested: false }"
        x-on:bea-ingested.window="ingested = true"
        x-init="$el.addEventListener('show.bs.modal', (e) => {
            const tab = e.relatedTarget?.dataset.beaTab;
            tab && bootstrap.Tab.getOrCreateInstance($el.querySelector('#beaImportTab-' + tab)).show();
        });
        $el.addEventListener('hidden.bs.modal', () => ingested && window.location.reload())">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-in-down"></i> beA-Nachrichten einlesen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="beaImportTab-import" data-bs-toggle="tab"
                                data-bs-target="#beaImportPane-import" type="button" role="tab">
                                <i class="bi bi-hdd-network"></i> Eingang
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="beaImportTab-upload" data-bs-toggle="tab"
                                data-bs-target="#beaImportPane-upload" type="button" role="tab">
                                <i class="bi bi-upload"></i> Hochladen
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="beaImportPane-import" role="tabpanel">
                            <livewire:bea.import :model-type="$modelType" :model-id="$modelId" :modal="true" />
                        </div>
                        <div class="tab-pane fade" id="beaImportPane-upload" role="tabpanel">
                            <livewire:bea.upload :model-type="$modelType" :model-id="$modelId" :modal="true" />
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter Modal --}}
    <div class="modal fade" id="beaFilterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-funnel"></i> Filter</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="GET" action="{{ route($listRoute) }}">
                    @foreach (['search', 'per_page', 'sort', 'direction', 'model_type', 'model_id'] as $param)
                        @if ($request->filled($param))
                            <input type="hidden" name="{{ $param }}" value="{{ $request->get($param) }}">
                        @endif
                    @endforeach
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Status</label>
                                <x-table.filter.dropdown field="status" :options="$statusOptions" :oldval="$request->status"
                                    label="Status" onchange="" />
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Zuordnung</label>
                                <x-table.filter.dropdown field="assigned" :options="$assignedOptions" :oldval="$request->assigned"
                                    label="Zuordnung" onchange="" />
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Prüfung</label>
                                <x-table.filter.dropdown field="check" :options="$checkOptions" :oldval="$request->check"
                                    label="Prüfung" onchange="" />
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Jahr</label>
                                <x-table.filter.dropdown field="jahr" :options="$jahre" :oldval="$request->jahr"
                                    label="Jahr" onchange="" />
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Richtung</label>
                                <x-table.filter.dropdown field="richtung" :options="$directionOptions" :oldval="$request->richtung"
                                    label="Richtung" onchange="" />
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="eeb" value="1"
                                        id="beaFilterEeb" @checked($request->boolean('eeb'))>
                                    <label class="form-check-label" for="beaFilterEeb">nur mit angefordertem
                                        eEB</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="{{ route($listRoute, array_filter($request->only(['search', 'per_page', 'model_type', 'model_id']))) }}"
                            class="btn btn-outline-danger">
                            <i class="bi bi-x-circle"></i> Zurücksetzen
                        </a>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Anwenden
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <x-slot name="footer">
        <x-counter :$startTime />
    </x-slot>

</x-dynamic-component>
