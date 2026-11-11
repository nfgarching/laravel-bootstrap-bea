{{-- Ergebnis des Einlesens ($results aus IngestsArchives) --}}
@foreach ($results as $result)
    <div class="alert alert-{{ $result['type'] }} py-2 px-3 mb-2 small d-flex align-items-center gap-2">
        <span>{{ $result['text'] }}</span>
        @if ($result['message_id'])
            <a class="btn btn-sm btn-primary ms-auto text-nowrap" href="{{ route('bea.select', $result['message_id']) }}">
                <i class="bi bi-check2-square"></i> Übernehmen
            </a>
        @endif
    </div>
@endforeach
