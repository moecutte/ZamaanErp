<?php

namespace App\Filament\Resources\SalesOrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';
    protected static ?string $title = 'Order Lines';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->label('Product'),
                TextColumn::make('batch.batch_code')
                    ->label('Batch')
                    ->placeholder('Not allocated'),
                TextColumn::make('quantity')->numeric(decimalPlaces: 3),
                TextColumn::make('unit_price')->formatStateUsing(fn ($state) => \App\Support\Money::format($state)),
                TextColumn::make('subtotal')->formatStateUsing(fn ($state) => \App\Support\Money::format($state)),
            ])
            ->paginated(false);
    }
}
