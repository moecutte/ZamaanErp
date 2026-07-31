<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WastageStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 12;

    protected function getStats(): array
    {
        $data = app(ReportService::class)->wastagePercent();

        return [
            Stat::make('Wastage % (30 days)', $data->wastage_pct . '%')
                ->description("Wasted {$data->wastage_qty} of {$data->total_out} outbound units")
                ->color($data->wastage_pct > 10 ? 'danger' : ($data->wastage_pct > 5 ? 'warning' : 'success')),
            Stat::make('Wastage Qty', number_format($data->wastage_qty, 3))
                ->description('Units written off')
                ->color('warning'),
            Stat::make('Sales Qty', number_format($data->sales_qty, 3))
                ->description('Units sold')
                ->color('success'),
        ];
    }
}
