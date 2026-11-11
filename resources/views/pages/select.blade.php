<x-app-layout>
    <x-slot name="header">
        @include('bea::parts.header', ['title' => 'beA-Nachricht übernehmen'])
    </x-slot>

    <livewire:bea.select :message-id="$message->id" />
</x-app-layout>
