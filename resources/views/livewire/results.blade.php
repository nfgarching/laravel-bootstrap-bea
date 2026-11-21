{{-- Ergebnis des Einlesens ($results aus IngestsArchives); im Modal ohne Schritt „Übernehmen“ --}}
@foreach ($results as $result)
    <div class="alert alert-{{ $result['type'] }} py-2 px-3 mb-2 small d-flex align-items-center gap-2">
        <span>{{ $result['text'] }}</span>
        @if ($result['message_id'] && !$modal)
            <x-button.dispatch class="btn btn-sm ms-auto text-nowrap btn-" frame frameSize="fullscreen"
                :href="route('bea.select', $result['message_id'])" :data="['title' => 'beA-Nachricht übernehmen']" tooltip="Dateien übernehmen">
                <i class="bi bi-check2-square"></i> Übernehmen
            </x-button.dispatch>
        @endif
    </div>
@endforeach
