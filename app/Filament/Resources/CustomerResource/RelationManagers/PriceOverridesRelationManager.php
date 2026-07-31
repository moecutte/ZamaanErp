<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceOverridesRelationManager extends RelationManager
{
    protected static string $relationship = 'priceOverrides';
    protected static ?string $title = 'Price Overrides';

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
                ->minValue(0)
                ->helperText('Negotiated rate for this customer — overrides the pricing tier.'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('price_per_unit')->money('USD')->sortable(),
                TextColumn::make('updated_at')->dateTime()->toggleable(),
            ])
            ->headerActions([CreateAction::make()])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
