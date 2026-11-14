{{-- Abschnitt der Seitenleiste (SidebarNavigation, BeaServiceProvider) --}}

<li class="btn-group nav-item w-100">
    <x-nav.link :href="route('bea.index')" :active="request()->routeIs('bea.index', 'bea.show', 'bea.select')">
        <i class="bi bi-envelope-paper"></i> {{ __('beA-Nachrichten') }}
    </x-nav.link>
    <a class="nav-link dropdown-toggle ms-auto" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"
        aria-label="Menü beA"></a>
    <ul class="dropdown-menu dropdown-menu-end">

        @if (Route::has('bea.index'))
            <li class="dropdown-item">
                <x-nav.link :href="route('bea.index')">
                    <i class="bi bi-list-ul"></i> {{ __('Nachrichten') }}
                </x-nav.link>
            </li>
        @endif

        {{-- Klick: Modal (bea::parts.modals), SHIFT: Popup --}}
        @if (Route::has('bea.pending'))
            <li class="dropdown-item">
                <button type="button" class="btn btn-link nav-link p-0" x-data
                    @click="$event.shiftKey
                        ? window.open('{{ route('bea.pending') }}', '_blank', 'width=1000,height=800')
                        : Livewire.dispatch('openModalBeaPending', { data: {} })">
                    <i class="bi bi-inbox"></i> {{ __('Nicht übernommen') }}
                </button>
            </li>
        @endif

        @if (Route::has('bea.import'))
            <li class="dropdown-item">
                <x-nav.link :href="route('bea.import')">
                    <i class="bi bi-box-arrow-in-down"></i> {{ __('Eingang einlesen') }}
                </x-nav.link>
            </li>
        @endif

        @if (Route::has('bea.upload'))
            <li class="dropdown-item">
                <x-nav.link :href="route('bea.upload')">
                    <i class="bi bi-upload"></i> {{ __('Hochladen') }}
                </x-nav.link>
            </li>
        @endif

    </ul>
</li>
