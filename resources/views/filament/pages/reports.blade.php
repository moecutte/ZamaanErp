<x-filament-panels::page>
    <form wire:submit="export">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit" color="primary" size="lg" icon="heroicon-o-arrow-down-tray">
                Download Report
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
