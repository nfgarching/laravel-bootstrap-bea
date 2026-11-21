{{-- Nicht übernommene beA-Nachrichten: Klick => iframe-Modal (xl), SHIFT => Popup, CTRL => Tab --}}
<x-nav.popup frame frameSize="xl" :href="route('bea.pending')" tooltip="beA-Nachrichten">
    <i class="{{ config('bea.icon', 'bi bi-bootstrap') }}"></i>
</x-nav.popup>
