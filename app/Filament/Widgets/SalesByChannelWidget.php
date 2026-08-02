<?php

namespace App\Filament\Widgets;

use App\Enums\SalesChannel;
use App\Services\ReportService;
use Filament\Widgets\ChartWidget;

class SalesByChannelWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Sales by channel (30 days)';
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = [
        'md' => 2,
        'xl' => 2,
    ];

    protected static bool $isLazy = true;

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
                    'backgroundColor' => ['#0d9488', '#0284c7'],
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
