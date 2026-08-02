<?php

namespace App\Filament\Widgets;

use App\Enums\CustomerType;
use App\Services\ReportService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class DashboardReportWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.widgets.dashboard-report';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** @var array{from?: string|null, to?: string|null} */
    public array $filters = [];

    public int $reportVersion = 0;

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
        ];

        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('from')
                    ->label('From')
                    ->native(true)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get): void {
                        $this->filters['from'] = filled($state)
                            ? Carbon::parse($state)->toDateString()
                            : now()->subDays(30)->toDateString();
                        $this->filters['to'] = filled($get('to'))
                            ? Carbon::parse($get('to'))->toDateString()
                            : ($this->filters['to'] ?? now()->toDateString());
                        $this->normalizeFilters();
                        $this->reportVersion++;
                    }),

                DatePicker::make('to')
                    ->label('To')
                    ->native(true)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get): void {
                        $this->filters['to'] = filled($state)
                            ? Carbon::parse($state)->toDateString()
                            : now()->toDateString();
                        $this->filters['from'] = filled($get('from'))
                            ? Carbon::parse($get('from'))->toDateString()
                            : ($this->filters['from'] ?? now()->subDays(30)->toDateString());
                        $this->normalizeFilters();
                        $this->reportVersion++;
                    }),
            ])
            ->columns(2)
            ->statePath('filters');
    }

    public function applyFilters(): void
    {
        $state = $this->form->getRawState();

        $this->filters['from'] = filled($state['from'] ?? null)
            ? Carbon::parse($state['from'])->toDateString()
            : now()->subDays(30)->toDateString();

        $this->filters['to'] = filled($state['to'] ?? null)
            ? Carbon::parse($state['to'])->toDateString()
            : now()->toDateString();

        $this->normalizeFilters();
        $this->form->fill($this->filters);
        $this->reportVersion++;
    }

    private function normalizeFilters(): void
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        if (! $from || ! $to) {
            return;
        }

        if (Carbon::parse($from)->gt(Carbon::parse($to))) {
            $this->filters['from'] = $to;
            $this->filters['to'] = $from;
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(): array
    {
        $from = Carbon::parse($this->filters['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($this->filters['to'] ?? now()->toDateString())->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        [$from, $to] = $this->dateRange();
        $report = app(ReportService::class)->dashboardReport($from, $to);

        $customerTypeKgLabels = $report['customer_type_kg']->map(
            fn ($row) => CustomerType::tryFrom($row->customer_type)?->label() ?? $row->customer_type
        )->values()->all();
        $customerTypeKgData = $report['customer_type_kg']->map(fn ($row) => round((float) $row->kg_sold, 3))->values()->all();

        $formLabels = $report['form_kg']->pluck('form')->values()->all();
        $formData = $report['form_kg']->map(fn ($row) => round((float) $row->kg_sold, 3))->values()->all();

        $productLabels = $report['product_kg']->pluck('product')->values()->all();
        $productData = $report['product_kg']->map(fn ($row) => round((float) $row->kg_sold, 3))->values()->all();

        $salespersonLabels = $report['salesperson_kg']->pluck('salesperson')->values()->all();
        $salespersonData = $report['salesperson_kg']->map(fn ($row) => round((float) $row->kg_sold, 3))->values()->all();

        $paidShare = $report['total_sales'] > 0
            ? round(($report['total_collection'] / $report['total_sales']) * 100, 1)
            : 0.0;
        $outstandingShare = $report['total_sales'] > 0
            ? round(($report['outstanding_debt'] / $report['total_sales']) * 100, 1)
            : 0.0;

        return [
            'report' => $report,
            'filterKey' => $from->toDateString() . '_' . $to->toDateString() . '_v' . $this->reportVersion,
            'charts' => [
                'revenueVsOutstanding' => [
                    'labels' => ['Collected', 'Outstanding'],
                    'data' => [$report['total_collection'], $report['outstanding_debt']],
                    'colors' => ['#0d9488', '#e11d48'],
                    'percents' => [$paidShare, $outstandingShare],
                ],
                'customerTypeKg' => [
                    'labels' => $customerTypeKgLabels,
                    'data' => $customerTypeKgData,
                    'colors' => ['#0d9488', '#0284c7', '#d97706'],
                ],
                'formSold' => [
                    'labels' => $formLabels,
                    'data' => $formData,
                    'colors' => ['#0d9488', '#0891b2', '#059669', '#4f46e5', '#ca8a04'],
                ],
                'salespersonKg' => [
                    'labels' => $salespersonLabels,
                    'data' => $salespersonData,
                    'colors' => ['#0d9488', '#14b8a6', '#2dd4bf', '#5eead4', '#99f6e4', '#ccfbf1'],
                ],
                'productSold' => [
                    'labels' => $productLabels,
                    'data' => $productData,
                    'colors' => [
                        '#0d9488', '#0891b2', '#0284c7', '#4f46e5', '#7c3aed',
                        '#db2777', '#e11d48', '#ea580c', '#ca8a04', '#65a30d',
                        '#059669', '#0f766e',
                    ],
                ],
            ],
        ];
    }
}
