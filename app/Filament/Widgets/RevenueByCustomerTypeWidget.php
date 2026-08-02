<?php

namespace App\Filament\Widgets;

use App\Enums\CustomerType;
use App\Services\ReportService;
use Filament\Widgets\ChartWidget;

class RevenueByCustomerTypeWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Revenue by Customer Type (30 days)';
    protected static ?int $sort = 11;
    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $rows = app(ReportService::class)->revenueByCustomerType();

        $labels = [];
        $data = [];

        foreach ($rows as $row) {
            $type = CustomerType::tryFrom($row->customer_type);
            $labels[] = $type?->label() ?? $row->customer_type;
            $data[] = round((float) $row->revenue, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenue ($)',
                    'data' => $data,
                    'backgroundColor' => ['#6366f1', '#f97316', '#22c55e'],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
