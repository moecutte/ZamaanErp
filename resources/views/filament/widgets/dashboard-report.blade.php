@php
    use App\Enums\CustomerType;
    $fmt = fn ($n, $d = 0) => number_format((float) $n, $d);
    $money = fn ($n) => \App\Support\Money::format($n);
    $top = fn ($rows, $n = 4) => collect($rows)->take($n);
@endphp

<x-filament-widgets::widget>
    <div class="space-y-3">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h2 class="text-base font-semibold tracking-tight text-gray-950 dark:text-white">
                    Sales overview
                </h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $report['from'] }} → {{ $report['to'] }}
                </p>
            </div>

            <div class="w-full max-w-md">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        {{ $this->form }}
                    </div>
                    <div class="shrink-0">
                        <x-filament::button type="button" wire:click="applyFilters" color="primary" size="sm">
                            Apply
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>

        <div wire:key="dashboard-report-{{ $filterKey }}" class="space-y-3">
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg bg-white px-3 py-2.5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Total sales</p>
                    <p class="mt-0.5 text-lg font-semibold tracking-tight text-gray-950 dark:text-white">{{ $money($report['total_sales']) }}</p>
                </div>
                <div class="rounded-lg bg-white px-3 py-2.5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Outstanding</p>
                    <p class="mt-0.5 text-lg font-semibold tracking-tight text-danger-600 dark:text-danger-400">{{ $money($report['outstanding_debt']) }}</p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $fmt($report['unpaid_invoices']) }} unpaid · {{ $fmt($report['customers_owing']) }} owing</p>
                </div>
                <div class="rounded-lg bg-white px-3 py-2.5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Collected</p>
                    <p class="mt-0.5 text-lg font-semibold tracking-tight text-primary-600 dark:text-primary-400">{{ $money($report['total_collection']) }}</p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $fmt($report['collection_rate'], 1) }}% · {{ $fmt($report['paid_invoices']) }} paid</p>
                </div>
                <div class="rounded-lg bg-white px-3 py-2.5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Volume sold</p>
                    <p class="mt-0.5 text-lg font-semibold tracking-tight text-gray-950 dark:text-white">{{ $fmt($report['total_kg_sold'], 1) }} <span class="text-sm font-medium text-gray-500">kg</span></p>
                </div>
            </div>

            <div class="grid gap-2 lg:grid-cols-12">
                <div class="lg:col-span-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-1">
                    <x-filament::section compact class="!p-0">
                        <x-slot name="heading">Outstanding by salesperson</x-slot>
                        <div class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($top($report['salesperson_outstanding']) as $row)
                                <div class="flex items-center justify-between gap-2 py-1.5 first:pt-0 last:pb-0">
                                    <span class="truncate text-xs font-medium text-gray-950 dark:text-white">{{ $row->salesperson }}</span>
                                    <span class="shrink-0 text-xs font-semibold text-danger-600 dark:text-danger-400">{{ $money($row->outstanding) }}</span>
                                </div>
                            @empty
                                <p class="text-xs text-gray-500">No outstanding</p>
                            @endforelse
                        </div>
                    </x-filament::section>

                    <x-filament::section compact>
                        <x-slot name="heading">Top products · kg</x-slot>
                        <div class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($top($report['product_kg']) as $row)
                                <div class="flex items-center justify-between gap-2 py-1.5 first:pt-0 last:pb-0">
                                    <span class="truncate text-xs font-medium text-gray-950 dark:text-white">{{ $row->product }}</span>
                                    <span class="shrink-0 text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $fmt($row->kg_sold, 1) }}</span>
                                </div>
                            @empty
                                <p class="text-xs text-gray-500">No sales</p>
                            @endforelse
                        </div>
                    </x-filament::section>
                </div>

                <div
                    class="lg:col-span-8 grid gap-2 sm:grid-cols-2 xl:grid-cols-4"
                    wire:ignore
                    x-data="{
                        charts: @js($charts),
                        key: @js($filterKey),
                        boot() {
                            const makeChart = (id, type, payload, options = {}) => {
                                const el = document.getElementById(id);
                                if (!el || typeof Chart === 'undefined') return;
                                const isDark = document.documentElement.classList.contains('dark');
                                const tick = isDark ? '#94a3b8' : '#64748b';
                                const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(148,163,184,0.25)';
                                new Chart(el, {
                                    type,
                                    data: {
                                        labels: payload.labels,
                                        datasets: [{
                                            data: payload.data,
                                            backgroundColor: payload.colors,
                                            borderWidth: type === 'bar' ? 0 : 2,
                                            borderColor: isDark ? '#0f172a' : '#ffffff',
                                            borderRadius: type === 'bar' ? 4 : 0,
                                        }],
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        cutout: type === 'doughnut' ? '58%' : undefined,
                                        plugins: {
                                            legend: {
                                                display: options.showLegend !== false && type !== 'bar',
                                                position: 'bottom',
                                                labels: {
                                                    boxWidth: 8,
                                                    usePointStyle: true,
                                                    pointStyle: 'circle',
                                                    padding: 8,
                                                    color: tick,
                                                    font: { size: 10 },
                                                },
                                            },
                                        },
                                        scales: type === 'bar' ? {
                                            x: { ticks: { color: tick, font: { size: 9 } }, grid: { display: false }, border: { display: false } },
                                            y: { beginAtZero: true, ticks: { color: tick, font: { size: 9 } }, grid: { color: grid }, border: { display: false } },
                                        } : undefined,
                                    },
                                });
                            };

                            const run = () => {
                                makeChart(`chart-revenue-outstanding-${this.key}`, 'doughnut', this.charts.revenueVsOutstanding);
                                makeChart(`chart-customer-type-kg-${this.key}`, 'bar', this.charts.customerTypeKg, { showLegend: false });
                                makeChart(`chart-form-sold-${this.key}`, 'doughnut', this.charts.formSold);
                                makeChart(`chart-salesperson-kg-${this.key}`, 'bar', this.charts.salespersonKg, { showLegend: false });
                            };

                            if (typeof Chart !== 'undefined') {
                                this.$nextTick(() => run());
                            } else {
                                const script = document.createElement('script');
                                script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
                                script.onload = () => run();
                                document.head.appendChild(script);
                            }
                        }
                    }"
                    x-init="boot()"
                >
                    <x-filament::section compact>
                        <x-slot name="heading">Collected vs outstanding</x-slot>
                        <div class="h-36">
                            <canvas id="chart-revenue-outstanding-{{ $filterKey }}"></canvas>
                        </div>
                    </x-filament::section>

                    <x-filament::section compact>
                        <x-slot name="heading">Customer type · kg</x-slot>
                        <div class="h-36">
                            <canvas id="chart-customer-type-kg-{{ $filterKey }}"></canvas>
                        </div>
                    </x-filament::section>

                    <x-filament::section compact>
                        <x-slot name="heading">Product form</x-slot>
                        <div class="h-36">
                            <canvas id="chart-form-sold-{{ $filterKey }}"></canvas>
                        </div>
                    </x-filament::section>

                    <x-filament::section compact>
                        <x-slot name="heading">Salesperson · kg</x-slot>
                        <div class="h-36">
                            <canvas id="chart-salesperson-kg-{{ $filterKey }}"></canvas>
                        </div>
                    </x-filament::section>
                </div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
