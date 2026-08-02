<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class LowStockWidget extends BaseWidget
{
    protected static ?string $heading = 'Low / zero stock';
    protected static ?int $sort = 3;
    protected int|string|array $columnSpan = 1;

    protected static bool $isLazy = true;

    /**
     * Products whose sellable (non-expired) qty is ≤ 10.
     */
    public function table(Table $table): Table
    {
        $sellableSub = DB::table('batches')
            ->selectRaw('product_id, COALESCE(SUM(quantity_available), 0) as sellable_qty')
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->groupBy('product_id');

        return $table
            ->query(
                Product::query()
                    ->leftJoinSub($sellableSub, 'sellable', 'sellable.product_id', '=', 'products.id')
                    ->select('products.*')
                    ->selectRaw('COALESCE(sellable.sellable_qty, 0) as sellable_qty')
                    ->whereRaw('COALESCE(sellable.sellable_qty, 0) <= 10')
                    ->orderByRaw('COALESCE(sellable.sellable_qty, 0) asc')
            )
            ->columns([
                TextColumn::make('name')->searchable()->limit(18),
                TextColumn::make('sku')->limit(12),
                TextColumn::make('sellable_qty')
                    ->label('Qty')
                    ->numeric(decimalPlaces: 1)
                    ->default('0.0')
                    ->color(fn ($state) => ((float) ($state ?? 0)) <= 0 ? 'danger' : 'warning'),
            ])
            ->paginated([5])
            ->defaultPaginationPageOption(5);
    }
}
