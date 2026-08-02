<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use Filament\Widgets\ChartWidget;

class StockAgingWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Stock Aging (by days to expiry)';
    protected static ?int $sort = 14;
    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $rows = app(ReportService::class)->stockAging();

        return [
            'datasets' => [
                [
                    'label' => 'Quantity Available',
                    'data' => $rows->pluck('quantity')->all(),
                    'backgroundColor' => ['#ef4444', '#f59e0b', '#eab308', '#3b82f6', '#22c55e'],
                ],
            ],
            'labels' => $rows->pluck('bucket')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
