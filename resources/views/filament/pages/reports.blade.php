<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Primary category tabs --}}
        <div class="flex max-w-full gap-1 overflow-x-auto rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            @foreach ($this->categoryTree as $key => $meta)
                <button
                    type="button"
                    wire:click="setCategory('{{ $key }}')"
                    @class([
                        'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition',
                        'bg-gray-50 text-primary-600 dark:bg-white/5 dark:text-primary-400' => $category === $key,
                        'text-gray-500 hover:bg-gray-50 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200' => $category !== $key,
                    ])
                >
                    {{ $meta['label'] }}
                </button>
            @endforeach
        </div>

        {{-- Sub-report tabs --}}
        <div class="rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap gap-1">
                @foreach ($this->categoryTree[$category]['reports'] as $reportKey => $reportLabel)
                    <button
                        type="button"
                        wire:click="selectSubReport('{{ $reportKey }}')"
                        @class([
                            'rounded-lg px-3 py-2 text-sm font-medium transition',
                            'bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-600/10 dark:bg-primary-400/10 dark:text-primary-400' => ($data['report'] ?? null) === $reportKey,
                            'text-gray-600 hover:bg-gray-50 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white' => ($data['report'] ?? null) !== $reportKey,
                        ])
                    >
                        {{ $reportLabel }}
                    </button>
                @endforeach
            </div>
        </div>

        <x-filament::section>
            <x-slot name="heading">
                Options
            </x-slot>
            <x-slot name="description">
                Preview the table below, then download as CSV or PDF.
            </x-slot>

            <form wire:submit="export" class="space-y-6" wire:key="report-options-{{ $category }}-{{ $data['report'] ?? 'none' }}">
                {{ $this->form }}

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <x-filament::button type="button" color="gray" wire:click="loadPreview" icon="heroicon-o-eye">
                        Refresh preview
                    </x-filament::button>
                    <x-filament::button type="submit" color="primary" icon="heroicon-o-arrow-down-tray">
                        Download
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ $preview['title'] ?? 'Preview' }}
            </x-slot>
            <x-slot name="description">
                @if (! empty($data['from']) && ! empty($data['to']))
                    {{ $data['from'] }} → {{ $data['to'] }}
                @else
                    Select options above to preview results.
                @endif
            </x-slot>

            @if ($preview)
                <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                    <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                @foreach ($preview['headers'] as $header)
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                        {{ $header }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white dark:divide-white/5 dark:bg-gray-900">
                            @forelse ($preview['rows'] as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                    @foreach ($row as $cell)
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-950 dark:text-gray-100">
                                            {{ $cell }}
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($preview['headers']) }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No data for this period.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ count($preview['rows']) }} row(s) in preview
                </p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No preview available yet.
                </p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
