<div>
    <div class="frame-hide"><br></div>
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
</div>
