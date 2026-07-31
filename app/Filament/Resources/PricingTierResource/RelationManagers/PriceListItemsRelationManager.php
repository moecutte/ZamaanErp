<?php

namespace App\Filament\Resources\PricingTierResource\RelationManagers;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceListItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'priceListItems';
    protected static ?string $title = 'Price List';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('product_id')
                ->relationship('product', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->columnSpan(2),

            TextInput::make('price_per_unit')
                ->numeric()
                ->prefix('$')
                ->required()
                ->minValue(0),

            TextInput::make('min_quantity')
                ->numeric()
                ->default(0)
                ->required()
                ->minValue(0)
                ->helperText('Minimum quantity to qualify for this price (quantity-break).'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('price_per_unit')->money('USD')->sortable(),
                TextColumn::make('min_quantity')
                    ->numeric(decimalPlaces: 3)
                    ->label('Min Qty')
                    ->sortable(),
            ])
            ->headerActions([CreateAction::make()])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('product_id');
    }
}
