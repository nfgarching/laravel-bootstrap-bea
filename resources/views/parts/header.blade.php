{{-- Kopfzeile der beA-Seiten: $title, optional $modelType/$modelId (Aufruf aus Akte, Dokument, Namen),
     $listRoute: Ziel von „Nachrichten“ (bea.index, im Popup bea.popup),
     $modal = true: „Eingang“/„Hochladen“ öffnen das Einlese-Modal der Liste (#beaImportModal) statt der Seiten --}}
@php
    $context = array_filter(['model_type' => $modelType ?? null, 'model_id' => $modelId ?? null]);
    $modal = $modal ?? false;
@endphp
<div class="container d-flex flex-wrap gap-2 align-items-center">
    <h5 class="mb-0 me-auto"><i class="bi bi-envelope-paper"></i> {{ $title }}</h5>
    <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="{{ route($listRoute ?? 'bea.index', $context) }}"><i class="bi bi-list-ul"></i> Nachrichten</a>
        @if ($modal)
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#beaImportModal"
                data-bea-tab="import"><i class="bi bi-hdd-network"></i> Eingang</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#beaImportModal"
                data-bea-tab="upload"><i class="bi bi-upload"></i> Hochladen</button>
        @else
            <a class="btn btn-outline-secondary" href="{{ route('bea.import', $context) }}"><i class="bi bi-hdd-network"></i> Eingang</a>
            <a class="btn btn-outline-secondary" href="{{ route('bea.upload', $context) }}"><i class="bi bi-upload"></i> Hochladen</a>
        @endif
    </div>
</div>
<hr>
