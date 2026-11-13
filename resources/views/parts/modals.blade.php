{{-- Über die ModalRegistry in jedes Layout eingebunden (BeaServiceProvider):
     nicht übernommene beA-Nachrichten, geöffnet per Event „openModalBeaPending“ --}}
@auth
    <livewire:bea.pending :modal="true" />
@endauth
