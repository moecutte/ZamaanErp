<?php

namespace App\Filament\Resources\PurchaseOrderResource\RelationManagers;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';
    protected static ?string $title = 'Order Lines';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('product_id')
                ->relationship('product', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->columnSpan(2),

            TextInput::make('quantity')
                ->numeric()
                ->required()
                ->minValue(0.001),

            TextInput::make('unit_cost')
                ->numeric()
                ->prefix('$')
                ->required()
                ->minValue(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('product.name')->label('Product'),
                TextColumn::make('quantity')->numeric(decimalPlaces: 3),
                TextColumn::make('unit_cost')->money('USD'),
                TextColumn::make('batch.batch_code')
                    ->label('Batch')
                    ->placeholder('Not received yet')
                    ->copyable(),
            ])
            ->paginated(false);
    }
}
