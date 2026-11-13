<div>
    @if ($modal)
        <x-modal :modid="$modalName" title="beA – nicht übernommene Nachrichten"
            class="modal-xl modal-dialog-scrollable">
            @if ($open)
                @include('bea::livewire.parts.pending-list')
            @endif

            <x-slot name="footer">
                @include('bea::livewire.parts.pending-footer')
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </x-slot>
        </x-modal>
    @else
        <br>
        <x-card>
            <x-slot name="headerLeft">
                <h5 class="card-title mb-0"><i class="bi bi-envelope-paper"></i> beA – nicht übernommene Nachrichten</h5>
            </x-slot>

            <x-slot name="headerRight">
                <small class="text-muted">{{ $this->contextLabel ?? 'ohne Zuordnung' }}</small>
            </x-slot>

            @include('bea::livewire.parts.pending-list')

            <x-slot name="footer">
                <div class="d-flex justify-content-end gap-2 mt-3">
                    @include('bea::livewire.parts.pending-footer')
                </div>
            </x-slot>
        </x-card>
    @endif
</div>
