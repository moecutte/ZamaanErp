<?php

namespace App\Filament\Widgets;

use App\Enums\SalesChannel;
use App\Services\ReportService;
use Filament\Widgets\ChartWidget;

class SalesByChannelWidget extends ChartWidget
{
    protected static ?string $heading = 'Sales by Channel (30 days)';
    protected static ?int $sort = 10;
    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $rows = app(ReportService::class)->salesByChannel();

        $labels = [];
        $data = [];

        foreach ($rows as $row) {
            $channel = SalesChannel::tryFrom($row->channel);
            $labels[] = $channel?->label() ?? $row->channel;
            $data[] = round((float) $row->revenue, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenue ($)',
                    'data' => $data,
                    'backgroundColor' => ['#3b82f6', '#f59e0b', '#10b981'],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
