<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Batch;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Services\ReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class OverviewStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $todayOrders = SalesOrder::query()
            ->whereDate('order_date', today())
            ->whereNot('status', SalesOrderStatus::Cancelled)
            ->count();

        $openInvoices = Invoice::query()
            ->whereIn('status', [
                InvoiceStatus::Unpaid,
                InvoiceStatus::Partial,
                InvoiceStatus::Overdue,
            ])
            ->whereHas('salesOrder', fn ($q) => $q->whereNot('status', SalesOrderStatus::Cancelled))
            ->count();

        $expiring = Batch::query()
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', Carbon::now()->addDays(3)->toDateString())
            ->count();

        $wastage = app(ReportService::class)->wastagePercent();
        $revenue = app(ReportService::class)->salesByChannel()
            ->sum(fn ($row) => (float) $row->revenue);

        return [
            Stat::make('Orders today', (string) $todayOrders)
                ->description('All channels')
                ->icon('heroicon-o-shopping-bag')
                ->color('primary'),
            Stat::make('Revenue (30 days)', \App\Support\Money::format($revenue))
                ->description('Confirmed / invoiced sales')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('Open invoices', (string) $openInvoices)
                ->description('Unpaid / partial / overdue')
                ->icon('heroicon-o-document-text')
                ->color($openInvoices > 0 ? 'warning' : 'success'),
            Stat::make('Expiring batches', (string) $expiring)
                ->description('Within 3 days')
                ->icon('heroicon-o-clock')
                ->color($expiring > 0 ? 'danger' : 'success'),
            Stat::make('Wastage (30 days)', $wastage->wastage_pct . '%')
                ->description(number_format($wastage->wastage_qty, 1) . ' units written off')
                ->icon('heroicon-o-trash')
                ->color($wastage->wastage_pct > 10 ? 'danger' : ($wastage->wastage_pct > 5 ? 'warning' : 'success')),
        ];
    }
}
