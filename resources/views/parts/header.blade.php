{{-- Kopfzeile der beA-Seiten: $title, optional $modelType/$modelId (Aufruf aus Akte, Dokument, Namen) --}}
@php
    $context = array_filter(['model_type' => $modelType ?? null, 'model_id' => $modelId ?? null]);
@endphp
<div class="container d-flex flex-wrap gap-2 align-items-center">
    <h5 class="mb-0 me-auto"><i class="bi bi-envelope-paper"></i> {{ $title }}</h5>
    <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="{{ route('bea.index') }}"><i class="bi bi-list-ul"></i> Nachrichten</a>
        <a class="btn btn-outline-secondary" href="{{ route('bea.import', $context) }}"><i class="bi bi-hdd-network"></i> Eingang</a>
        <a class="btn btn-outline-secondary" href="{{ route('bea.upload', $context) }}"><i class="bi bi-upload"></i> Hochladen</a>
    </div>
</div>
<hr>
