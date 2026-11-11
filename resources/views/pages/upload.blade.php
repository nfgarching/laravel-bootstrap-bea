<x-app-layout>
    <x-slot name="header">
        @include('bea::parts.header', ['title' => 'beA-Nachrichten hochladen'])
    </x-slot>

    <livewire:bea.upload :model-type="$modelType" :model-id="$modelId" />
</x-app-layout>
