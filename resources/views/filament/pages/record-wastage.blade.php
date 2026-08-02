<x-filament-panels::page>
    <x-filament::tabs label="Wastage tabs">
        <x-filament::tabs.item
            wire:click="setActiveTab('record')"
            :active="$activeTab === 'record'"
        >
            Record wastage
        </x-filament::tabs.item>

        <x-filament::tabs.item
            wire:click="setActiveTab('history')"
            :active="$activeTab === 'history'"
        >
            Waste history
        </x-filament::tabs.item>
    </x-filament::tabs>

    <div class="mt-6">
        @if ($activeTab === 'record')
            <x-filament::section>
                <x-slot name="heading">
                    Record wastage / spoilage
                </x-slot>
                <x-slot name="description">
                    Write off damaged, expired, or rejected stock. Processing waste is logged from Process Form.
                </x-slot>

                <form wire:submit="submit">
                    {{ $this->form }}

                    <div class="mt-6 flex justify-end">
                        <x-filament::button type="submit" color="danger" size="lg">
                            Record Wastage
                        </x-filament::button>
                    </div>
                </form>
            </x-filament::section>
        @else
            {{ $this->table }}
        @endif
    </div>
</x-filament-panels::page>
