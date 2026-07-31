<x-filament-panels::page>
    <form wire:submit="create">
        {{ $this->form }}

        <div class="mt-6 flex justify-end gap-3">
            <x-filament::button type="submit" color="primary" size="lg">
                {{ $this->autoConfirmLabel() }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
