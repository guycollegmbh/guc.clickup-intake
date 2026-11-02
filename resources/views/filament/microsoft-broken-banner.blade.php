<x-filament::callout
    color="danger"
    icon="heroicon-o-exclamation-triangle"
    heading="Die Verbindung zu Microsoft 365 ist unterbrochen. Bitte ab- und wieder anmelden."
    class="mb-6"
>
    <x-slot name="controls">
        <form method="POST" action="{{ filament()->getLogoutUrl() }}">
            @csrf
            <x-filament::button type="submit" color="danger" size="sm">
                Abmelden
            </x-filament::button>
        </form>
    </x-slot>
</x-filament::callout>
