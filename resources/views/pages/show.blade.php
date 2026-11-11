<x-app-layout>
    <x-slot name="header">
        @include('bea::parts.header', ['title' => 'beA-Nachricht '.($message->bea_id ?? '#'.$message->id)])
    </x-slot>

    @php
        $data = $message->data ?? [];
        $latest = $message->verifications->first();
        $tz = config('app.timezone');
        $date = fn ($value) => $value?->timezone($tz)->format('d.m.Y H:i') ?? '–';
        $tabs = ['nachricht' => 'Nachricht', 'dateien' => 'Dateien', 'pruefung' => 'Prüfung', 'journal' => 'Journal'];
        $tab = array_key_exists($tab, $tabs) ? $tab : 'nachricht';
    @endphp

    @if (session('message'))
        <div class="alert alert-info py-2">{{ session('message') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <div class="me-auto">
                <div class="fw-semibold">
                    {{ $message->subject ?: '(ohne Betreff)' }}
                    @if ($message->eeb_requested)
                        <span class="badge text-bg-danger" title="Elektronisches Empfangsbekenntnis angefordert">eEB</span>
                    @endif
                </div>
                <div class="small text-muted">{{ $message->sender_name }} · Eingang {{ $date($message->received_at) }}</div>
            </div>
            <span class="text-nowrap">@include('bea::parts.badges', ['item' => $message, 'verification' => $latest])</span>
            <span class="badge text-bg-{{ $message->isAccepted() ? 'success' : 'warning' }}">{{ $message->isAccepted() ? 'übernommen' : 'eingelesen' }}</span>
            <a class="btn btn-sm btn-primary" href="{{ route('bea.select', $message) }}">
                <i class="bi bi-check2-square"></i> {{ $message->isAccepted() ? 'Auswahl/Zuordnung' : 'Übernehmen' }}
            </a>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        @foreach ($tabs as $key => $label)
            <li class="nav-item">
                <a @class(['nav-link', 'active' => $tab === $key]) href="{{ route('bea.show', ['message' => $message, 'tab' => $key]) }}">{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    {{-- Nachricht --}}
    @if ($tab === 'nachricht')
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">Kopfdaten</div>
                    <table class="table table-sm mb-0 small">
                        <tr><th class="w-25">beA-ID</th><td>{{ $message->bea_id ?? '–' }}</td></tr>
                        <tr><th>OSCI-ID</th><td class="text-break">{{ $message->osci_id ?? '–' }}</td></tr>
                        <tr><th>Absender</th><td>{{ $message->sender_name ?? '–' }}<div class="text-muted text-break">{{ $message->sender_safe_id }}</div></td></tr>
                        <tr><th>Rolle</th><td>{{ $message->sender_role ?? '–' }}</td></tr>
                        <tr><th>Empfänger</th><td>{{ $message->recipient_name ?? '–' }}<div class="text-muted text-break">{{ $message->recipient_safe_id }}</div></td></tr>
                        <tr><th>Unser AZ</th><td>{{ $message->aktenzeichen ?? '–' }}</td></tr>
                        <tr><th>AZ Absender</th><td>{{ $message->aktenzeichen_absender ?? '–' }}</td></tr>
                        <tr><th>Gesendet</th><td>{{ $date($message->sent_at) }}</td></tr>
                        <tr><th>Eingang Postfach</th><td>{{ $date($message->received_at) }}</td></tr>
                        <tr><th>Eingang Intermediär</th><td>{{ $date($message->intermediary_at) }}</td></tr>
                        <tr><th>Eingelesen</th><td>{{ $date($message->created_at) }}</td></tr>
                        <tr><th>Übernommen</th><td>{{ $date($message->accepted_at) }}</td></tr>
                    </table>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-header">Zuordnung</div>
                    <div class="card-body small">
                        @forelse ($assignments as $index => $label)
                            <div>
                                @if ($index === 0)
                                    <i class="bi bi-star-fill text-warning" title="Hauptzuordnung"></i>
                                @endif
                                {{ $label }}
                            </div>
                        @empty
                            <span class="text-muted">Nicht zugeordnet.</span>
                        @endforelse
                    </div>
                </div>
                <div class="card mb-3">
                    <div class="card-header">Verfahren (XJustiz)</div>
                    <div class="card-body small">
                        @forelse ($data['xjustiz']['instances'] ?? [] as $instance)
                            <div>
                                {{ $instance['aktenzeichen'] ?? '–' }}
                                @if (! empty($instance['subject_matter'])) · {{ $instance['subject_matter'] }} @endif
                                <span class="text-muted">(Gericht {{ $instance['court'] ?? '–' }}, Sachgebiet {{ $instance['subject_area'] ?? '–' }})</span>
                            </div>
                        @empty
                            <span class="text-muted">Keine Angaben.</span>
                        @endforelse
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">Beteiligte (XJustiz)</div>
                    <div class="card-body small">
                        @forelse ($data['xjustiz']['parties'] ?? [] as $party)
                            <div>{{ $party['name'] ?? '–' }} <span class="text-muted">(Rolle {{ implode(', ', $party['roles'] ?? []) ?: '–' }})</span></div>
                        @empty
                            <span class="text-muted">Keine Angaben.</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Dateien --}}
    @if ($tab === 'dateien')
        <div class="card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <span>Alle Dateien der Nachricht in der ZIP</span>
                @if ($archiveExists)
                    <a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('bea.archive', $message) }}">
                        <i class="bi bi-file-earmark-zip"></i> Original-ZIP
                    </a>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle small">
                    <thead>
                        <tr>
                            <th>Datei</th>
                            <th>Art</th>
                            <th class="text-end">Größe</th>
                            <th>SHA-256</th>
                            <th>VHN</th>
                            <th>Übernahme</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $row)
                            <tr>
                                <td>
                                    {{ $row->label() }}
                                    @if ($row->display_name && $row->display_name !== $row->name)
                                        <div class="text-muted">{{ $row->name }}</div>
                                    @endif
                                    @if ($row->signed)
                                        <span class="badge text-bg-dark" title="Signatur {{ $row->signature_name }} in der ZIP"><i class="bi bi-patch-check"></i> signiert</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @switch($row->role)
                                        @case('attachment')
                                            {{ $row->categoryLabel() ?? 'Anhang' }}
                                            @if ($row->suggested)
                                                <i class="bi bi-lightbulb text-warning" title="zur Übernahme vorgeschlagen"></i>
                                            @endif
                                        @break

                                        @case('signature')
                                            Signatur zu {{ $row->signature_of }}
                                        @break

                                        @default
                                            <span class="text-muted">technisch ({{ $row->kind }})</span>
                                    @endswitch
                                </td>
                                <td class="text-end text-nowrap">{{ Number::fileSize((int) $row->size) }}</td>
                                <td><code class="text-break" title="{{ $row->sha256 }}">{{ \Illuminate\Support\Str::limit((string) $row->sha256, 16) }}</code></td>
                                <td>
                                    @if ($row->vhn_covered === true)
                                        <i class="bi bi-check-lg text-success" title="Prüfsumme im Herkunftsnachweis"></i>
                                    @elseif ($row->vhn_covered === false && $row->role !== 'signature')
                                        <i class="bi bi-exclamation-triangle text-warning" title="keine passende Prüfsumme im Herkunftsnachweis"></i>
                                    @else
                                        <span class="text-muted">–</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @if ($row->isAccepted())
                                        {{ $date($row->accepted_at) }}
                                        @if ($row->file && Route::has('files.show'))
                                            <a href="{{ route('files.show', $row->file) }}" title="Datei #{{ $row->file_id }} öffnen"><i class="bi bi-box-arrow-up-right"></i></a>
                                        @elseif (! $row->file)
                                            <span class="text-muted" title="Die Datei wurde im Files-Package gelöscht">(gelöscht)</span>
                                        @endif
                                    @elseif ($row->role === 'attachment')
                                        <span class="text-muted">nicht übernommen</span>
                                    @else
                                        <span class="text-muted">bleibt in der ZIP</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Prüfung --}}
    @if ($tab === 'pruefung')
        @php
            $vhn = $latest?->result['vhn'] ?? ($data['vhn_signature'] ?? []);
            $integrity = $latest?->result['integrity'] ?? ($data['integrity'] ?? []);
            $mark = fn (?bool $ok) => $ok === null
                ? '<span class="text-muted">nicht geprüft</span>'
                : ($ok ? '<span class="text-success"><i class="bi bi-check-lg"></i> ja</span>' : '<span class="text-danger"><i class="bi bi-x-lg"></i> nein</span>');
        @endphp
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <span>Aktueller Stand</span>
                        <form method="POST" action="{{ route('bea.verify', $message) }}" class="ms-auto">
                            @csrf
                            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-arrow-repeat"></i> Erneut prüfen</button>
                        </form>
                    </div>
                    <table class="table table-sm mb-0 small">
                        <tr><th class="w-50">Original-ZIP vorhanden</th><td>{!! $mark($archiveExists) !!}</td></tr>
                        <tr><th>ZIP unverändert seit dem Einlesen</th><td>{!! $mark($latest?->archive_intact) !!}</td></tr>
                        <tr><th>Dateien entsprechen den erfassten Prüfsummen</th><td>{!! $mark($latest?->files_intact) !!}</td></tr>
                        <tr><th>Prüfsummen im Herkunftsnachweis</th><td>
                            {{ match ($message->integrity) { 'complete' => 'vollständig', 'incomplete' => 'abweichend', default => 'kein Herkunftsnachweis' } }}
                            @if (isset($integrity['vhn_hashes']))
                                <span class="text-muted">({{ count($integrity['matched'] ?? []) }} von {{ $integrity['vhn_hashes'] }} zugeordnet)</span>
                            @endif
                        </td></tr>
                        <tr><th>Signatur des Herkunftsnachweises</th><td>
                            {{ match ($message->vhn_signature) {
                                'valid' => 'gültig, Zertifikatskette geprüft',
                                'signature_ok' => 'mathematisch gültig, Kette nicht geprüft',
                                'invalid' => 'ungültig',
                                'missing' => 'nicht vorhanden',
                                'notool' => 'nicht geprüft (OpenSSL fehlt)',
                                'disabled' => 'Prüfung abgeschaltet',
                                default => (string) $message->vhn_signature,
                            } }}
                        </td></tr>
                        <tr><th>Sicherer Übermittlungsweg</th><td>{!! $mark($message->secure_channel) !!}</td></tr>
                        <tr><th>Signiert von</th><td>{{ $vhn['signer'] ?? '–' }}</td></tr>
                        <tr><th>Aussteller</th><td>{{ $vhn['issuer'] ?? '–' }}</td></tr>
                        <tr><th>Signaturzeit</th><td>{{ $vhn['signed_at'] ?? '–' }}</td></tr>
                    </table>
                    @if (! empty($vhn['error']))
                        <div class="card-body border-top small text-danger text-break">{{ $vhn['error'] }}</div>
                    @endif
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-header">Original-ZIP</div>
                    <table class="table table-sm mb-0 small">
                        <tr><th class="w-25">Datei</th><td class="text-break">{{ $message->archive?->original_name ?? '–' }}</td></tr>
                        <tr><th>Ablage</th><td class="text-break">{{ $message->archive ? $message->archive->disk.'/'.$message->archive->path : '–' }}</td></tr>
                        <tr><th>SHA-256</th><td><code class="text-break">{{ $message->archive?->sha256 ?? '–' }}</code></td></tr>
                        <tr><th>Größe</th><td>{{ $message->archive ? Number::fileSize((int) $message->archive->size) : '–' }}</td></tr>
                        <tr><th>Quelle</th><td>{{ $message->archive?->source ?? '–' }}</td></tr>
                    </table>
                </div>
                @php
                    $issues = array_merge(
                        array_map(fn ($name) => 'Nicht vom Herkunftsnachweis erfasst: '.$name, $integrity['uncovered'] ?? []),
                        ($integrity['unmatched_hashes'] ?? 0) > 0 ? [($integrity['unmatched_hashes']).' Prüfsumme(n) im Herkunftsnachweis ohne passende Datei'] : [],
                        $latest?->result['warnings'] ?? ($data['warnings'] ?? []),
                        ! empty($latest?->result['error']) ? [$latest->result['error']] : [],
                    );
                @endphp
                <div class="card">
                    <div class="card-header">Beanstandungen</div>
                    <div class="card-body small">
                        @forelse (array_unique($issues) as $issue)
                            <div class="text-danger"><i class="bi bi-exclamation-triangle"></i> {{ $issue }}</div>
                        @empty
                            <span class="text-success"><i class="bi bi-check-lg"></i> Keine.</span>
                        @endforelse
                    </div>
                </div>
            </div>

            @if (! empty($latest?->result['files']))
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">Dateien bei der letzten Prüfung ({{ $date($latest->created_at) }})</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 small">
                                <thead><tr><th>Datei</th><th>erfasst</th><th>gefunden</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($latest->result['files'] as $file)
                                        <tr @class(['table-danger' => ! $file['ok']])>
                                            <td>{{ $file['name'] }}</td>
                                            <td><code>{{ \Illuminate\Support\Str::limit((string) $file['expected'], 16) }}</code></td>
                                            <td><code>{{ $file['found'] ? \Illuminate\Support\Str::limit($file['found'], 16) : 'fehlt' }}</code></td>
                                            <td>{!! $mark($file['ok']) !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            <div class="col-12">
                <div class="card">
                    <div class="card-header">Prüfverlauf</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 small">
                            <thead>
                                <tr><th>Zeitpunkt</th><th>Anlass</th><th>ZIP unverändert</th><th>Dateien unverändert</th><th>VHN-Prüfsummen</th><th>VHN-Signatur</th><th>Ergebnis</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($message->verifications as $verification)
                                    <tr>
                                        <td class="text-nowrap">{{ $date($verification->created_at) }}</td>
                                        <td>{{ match ($verification->trigger) { 'ingest' => 'Einlesen', 'accept' => 'Übernahme', 'manual' => 'von Hand', default => $verification->trigger } }}</td>
                                        <td>{!! $mark($verification->archive_intact) !!}</td>
                                        <td>{!! $mark($verification->files_intact) !!}</td>
                                        <td>{{ $verification->integrity ?? '–' }}</td>
                                        <td>{{ $verification->vhn_signature ?? '–' }}</td>
                                        <td>
                                            @if ($verification->passed())
                                                <span class="badge text-bg-success">ohne Beanstandung</span>
                                            @else
                                                <span class="badge text-bg-danger">Beanstandung</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Journal --}}
    @if ($tab === 'journal')
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">Nachrichtenjournal</div>
                    <table class="table table-sm mb-0 small">
                        @forelse ($data['journal'] ?? [] as $entry)
                            <tr>
                                @foreach ($entry as $value)
                                    <td>{{ $value }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td class="text-muted">Kein Journal in der Exportansicht.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">Felder der Exportansicht</div>
                    <table class="table table-sm mb-0 small">
                        @forelse ($data['export_fields'] ?? [] as $label => $value)
                            <tr><th class="w-50">{{ $label }}</th><td class="text-break">{{ $value }}</td></tr>
                        @empty
                            <tr><td class="text-muted">Keine Exportansicht in der Nachricht.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
    @endif
</x-app-layout>
