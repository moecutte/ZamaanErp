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
    protected static ?string $heading = '⚠ Batches Expiring Within 3 Days';
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Batch::query()
                    ->with(['product', 'supplier'])
                    ->where('quantity_available', '>', 0)
                    ->where('expiry_date', '<=', Carbon::now()->addDays(3))
                    ->orderBy('expiry_date', 'asc')
            )
            ->columns([
                TextColumn::make('batch_code')
                    ->searchable(),
                TextColumn::make('product.name')
                    ->label('Product'),
                TextColumn::make('expiry_date')
                    ->date()
                    ->sortable()
                    ->color(fn (Batch $record) => $record->expiry_date->isPast() ? 'danger' : 'warning'),
                TextColumn::make('quantity_available')
                    ->numeric(decimalPlaces: 3)
                    ->label('Qty Available'),
                TextColumn::make('storage_location')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->toggleable(),
            ])
            ->paginated(false);
    }
}
