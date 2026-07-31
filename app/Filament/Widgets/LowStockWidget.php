<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LowStockWidget extends BaseWidget
{
    protected static ?string $heading = '📦 Low / Zero Stock Products';
    protected static ?int $sort = 3;
    protected int|string|array $columnSpan = 'full';

    /**
     * Products whose total available quantity across all batches is ≤ 10.
     * This threshold is intentionally low for an MVP; a per-product reorder
     * point field can be added in a future phase.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->withSum('batches', 'quantity_available')
                    ->having('batches_sum_quantity_available', '<=', 10)
                    ->orHavingNull('batches_sum_quantity_available')
                    ->orderBy('batches_sum_quantity_available', 'asc')
            )
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('sku')->copyable(),
                TextColumn::make('unit_type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('batches_sum_quantity_available')
                    ->label('Total Available')
                    ->numeric(decimalPlaces: 3)
                    ->default('0.000')
                    ->color(fn ($state) => ((float) ($state ?? 0)) <= 0 ? 'danger' : 'warning'),
            ])
            ->paginated(false);
    }
}
