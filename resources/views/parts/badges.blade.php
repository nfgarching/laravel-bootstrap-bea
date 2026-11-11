{{-- Prüfstatus einer Nachricht: $item (BeaMessage), optional $verification (BeaVerification) --}}
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
    $verification = $verification ?? null;
@endphp
<span class="badge text-bg-{{ $vhn[0] }}">{{ $vhn[1] }}</span>
<span class="badge text-bg-{{ $hash[0] }}">{{ $hash[1] }}</span>
@if ($item->secure_channel)
    <span class="badge text-bg-light border" title="Sicherer Übermittlungsweg">SÜW</span>
@endif
@if ($verification && ($verification->archive_intact === false || $verification->files_intact === false))
    <span class="badge text-bg-danger" title="Die Original-ZIP oder eine Datei darin entspricht nicht mehr dem Stand beim Einlesen">ZIP verändert</span>
@endif
