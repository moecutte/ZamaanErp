<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TopProductsWidget extends BaseWidget
{
    protected static ?string $heading = 'Top Products (30 days)';
    protected static ?int $sort = 13;
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $rows = app(ReportService::class)->topProducts(10);

        // TableWidget needs an Eloquent query — use a raw subquery via Product with joins
        return $table
            ->query(
                \App\Models\Product::query()
                    ->select([
                        'products.id',
                        'products.name',
                        'products.sku',
                        DB::raw('COALESCE(SUM(sales_order_lines.quantity), 0) as total_qty'),
                        DB::raw('COALESCE(SUM(sales_order_lines.subtotal), 0) as revenue'),
                    ])
                    ->leftJoin('sales_order_lines', 'sales_order_lines.product_id', '=', 'products.id')
                    ->leftJoin('sales_orders', function ($join) {
                        $join->on('sales_orders.id', '=', 'sales_order_lines.sales_order_id')
                            ->whereNotIn('sales_orders.status', ['draft', 'cancelled'])
                            ->whereBetween('sales_orders.order_date', [
                                now()->subDays(30)->toDateString(),
                                now()->toDateString(),
                            ]);
                    })
                    ->groupBy('products.id', 'products.name', 'products.sku')
                    ->havingRaw('COALESCE(SUM(sales_order_lines.quantity), 0) > 0')
                    ->orderByDesc('total_qty')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('name')->label('Product'),
                TextColumn::make('sku')->label('SKU'),
                TextColumn::make('total_qty')
                    ->label('Qty Sold')
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->money('USD'),
            ])
            ->paginated(false);
    }
}
