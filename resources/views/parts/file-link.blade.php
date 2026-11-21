{{--
    Vorschau-Knopf einer Anlage aus der Original-ZIP (bea.file), wie x-storage.link (files):
    Klick => Vorschau im Fullscreen-Modal (bea::parts.file-modal, einmal je Seite einbinden),
    SHIFT => Popup, CTRL => Tab; nicht darstellbare Dateien öffnen im Tab (Download).
    Parameter: $message (BeaMessage), $row (BeaFile)
--}}
@php
    $fileType = \Nfgarching\Files\Enums\FileType::fromExtension(pathinfo((string) $row->name, PATHINFO_EXTENSION));
    $previewable = $row->isPreviewable();
@endphp
<button type="button" class="btn btn-sm btn-outline-{{ $fileType->color() }}"
    data-url="{{ route('bea.file', ['message' => $message->id, 'messageFile' => $row->id]) }}"
    data-name="{{ $row->label() }}"
    data-icon="{{ $fileType->icon() }}"
    data-previewable="{{ $previewable ? '1' : '0' }}"
    title="{{ $previewable ? 'Vorschau (SHIFT: Popup, CTRL: Tab)' : 'Öffnen (keine Vorschau möglich)' }}"
    onclick="beaFilePreview(event, this)">
    <i class="{{ $fileType->icon() }}"></i>
</button>
