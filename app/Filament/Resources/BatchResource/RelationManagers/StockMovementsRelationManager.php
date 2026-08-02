<?php

namespace App\Filament\Resources\BatchResource\RelationManagers;

use App\Enums\StockMovementType;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';
    protected static ?string $title = 'Stock Movement History';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (StockMovementType $state) => $state->label())
                    ->colors([
                        'success' => StockMovementType::PurchaseIn->value,
                        'danger'  => StockMovementType::SaleOut->value,
                        'warning' => StockMovementType::WastageOut->value,
                        'info'    => StockMovementType::Adjustment->value,
                        'primary' => StockMovementType::ProcessingIn->value,
                        'gray'    => StockMovementType::ProcessingOut->value,
                    ]),
                TextColumn::make('quantity')
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('reason')
                    ->limit(50)
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label('By'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25]);
    }
}
