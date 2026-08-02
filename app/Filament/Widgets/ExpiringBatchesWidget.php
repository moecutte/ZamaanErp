<?php

namespace App\Filament\Widgets;

use App\Models\Batch;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ExpiringBatchesWidget extends BaseWidget
{
    protected static ?string $heading = 'Expiring ≤ 3 days';
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 1;

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Batch::query()
                    ->with(['product', 'supplier'])
                    ->where('quantity_available', '>', 0)
                    ->whereDate('expiry_date', '>=', Carbon::now()->toDateString())
                    ->whereDate('expiry_date', '<=', Carbon::now()->addDays(3)->toDateString())
                    ->orderBy('expiry_date', 'asc')
            )
            ->columns([
                TextColumn::make('batch_code')
                    ->label('Batch')
                    ->searchable()
                    ->limit(14),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->limit(16),
                TextColumn::make('expiry_date')
                    ->date()
                    ->sortable()
                    ->color(fn (Batch $record) => $record->expiry_date->isPast() ? 'danger' : 'warning'),
                TextColumn::make('quantity_available')
                    ->numeric(decimalPlaces: 1)
                    ->label('Qty'),
            ])
            ->paginated([5])
            ->defaultPaginationPageOption(5);
    }
}
