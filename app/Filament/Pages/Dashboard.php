<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DashboardReportWidget;
use App\Filament\Widgets\ExpiringBatchesWidget;
use App\Filament\Widgets\LowStockWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?string $navigationLabel = 'Dashboard';
    protected static ?int $navigationSort = -2;
    protected static ?string $title = 'Dashboard';

    public function getColumns(): int|string|array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 2,
        ];
    }

    public function getWidgets(): array
    {
        return [
            DashboardReportWidget::class,
            ExpiringBatchesWidget::class,
            LowStockWidget::class,
        ];
    }
}
