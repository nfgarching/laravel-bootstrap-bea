<x-app-layout>
    <x-slot name="header">
        @include('bea::parts.header', ['title' => 'beA-Eingang'])
    </x-slot>

    <livewire:bea.import :model-type="$modelType" :model-id="$modelId" />
</x-app-layout>
