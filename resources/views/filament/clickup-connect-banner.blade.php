<x-filament::callout
    :color="$broken ? 'danger' : 'warning'"
    icon="heroicon-o-link"
    :heading="$broken ? 'Die Verbindung zu ClickUp ist unterbrochen. Verbinde dein ClickUp-Konto neu, um Tasks zu erstellen.' : 'Verbinde dein ClickUp-Konto, um Tasks zu erstellen.'"
    class="mb-6"
>
    <x-slot name="controls">
        <x-filament::button tag="a" :href="route('clickup.connect')" size="sm">
            Mit ClickUp verbinden
        </x-filament::button>
    </x-slot>
</x-filament::callout>
