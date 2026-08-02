<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\ProductForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
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
                ->live()
                ->afterStateUpdated(fn (callable $set) => $set('product_form_id', null))
                ->columnSpan(2),

            Select::make('product_form_id')
                ->label('Form')
                ->options(function (Get $get) {
                    $productId = $get('product_id');
                    if (! $productId) {
                        return [];
                    }

                    return ProductForm::query()
                        ->where('product_id', $productId)
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->pluck('name', 'id');
                })
                ->helperText('Leave empty to apply to all forms (fallback).')
                ->nullable(),

            TextInput::make('price_per_unit')
                ->numeric()
                ->suffix(' ' . \App\Support\Money::label())
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
                TextColumn::make('productForm.name')
                    ->label('Form')
                    ->placeholder('All forms')
                    ->badge(),
                TextColumn::make('price_per_unit')->formatStateUsing(fn ($state) => \App\Support\Money::format($state))->sortable(),
                TextColumn::make('updated_at')->dateTime()->toggleable(),
            ])
            ->headerActions([CreateAction::make()])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
