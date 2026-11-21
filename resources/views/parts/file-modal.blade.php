{{--
    Ein gemeinsames Vorschau-Modal für bea::parts.file-link (wie das Modal von
    x-storage.link, files): die Datei wird erst beim Öffnen geladen, nicht für
    jede Anlage beim Rendern. Im iframe-Modal (frameSize="fullscreen") füllt es
    das ganze Modal.
    wire:ignore: Livewire-Morph würde sonst iframe-src und die von Bootstrap
    gesetzten Attribute (show, display:block) zurücksetzen.
--}}
<div class="modal fade" id="beaFileModal" wire:ignore tabindex="-1" aria-labelledby="beaFileModal-label" aria-hidden="true">
    <div class="modal-dialog modal-{{ $size ?? 'fullscreen' }}">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title text-truncate" id="beaFileModal-label">
                    <i class="bi bi-file-earmark me-2" data-bea-file-icon></i><span data-bea-file-name></span>
                </h5>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <a href="#" target="_blank" class="btn btn-sm btn-outline-secondary" data-bea-file-tab
                        title="In neuem Tab öffnen"><i class="bi bi-box-arrow-up-right"></i></a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
            </div>
            {{-- iframe absolut: füllt den Körper; außer im Fullscreen-Modal braucht
                 der Körper dafür eine feste Höhe --}}
            <div class="modal-body p-0 position-relative overflow-hidden"
                @if (($size ?? 'fullscreen') !== 'fullscreen') style="height: 80vh" @endif>
                <iframe src="about:blank" class="position-absolute top-0 start-0 w-100 h-100 border-0" title="Vorschau"></iframe>
            </div>
        </div>
    </div>
</div>

@assets
    <script>
        window.beaFilePreview = function (event, button) {
            const url = button.dataset.url;
            if (event.ctrlKey || event.metaKey || button.dataset.previewable !== '1') {
                window.open(url, '_blank');
                return;
            }
            if (event.shiftKey) {
                // Benanntes Ziel: weitere SHIFT-Klicks nutzen dasselbe Popup
                window.open(url, 'beaPreview', 'left=100,top=100,width=800,height=900');
                return;
            }

            const modal = document.getElementById('beaFileModal');
            modal.querySelector('iframe').src = url;
            modal.querySelector('[data-bea-file-name]').textContent = button.dataset.name || '';
            modal.querySelector('[data-bea-file-icon]').className = (button.dataset.icon || 'bi bi-file-earmark') + ' me-2';
            modal.querySelector('[data-bea-file-tab]').href = url;
            if (!modal.dataset.bound) {
                // Beim Schließen entladen (PDF-Viewer, Speicher)
                modal.addEventListener('hidden.bs.modal', () => modal.querySelector('iframe').src = 'about:blank');
                modal.dataset.bound = '1';
            }
            bootstrap.Modal.getOrCreateInstance(modal).show();
        };
    </script>
@endassets
