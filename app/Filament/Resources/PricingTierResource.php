<?php

namespace App\Filament\Resources;

use App\Enums\CustomerType;
use App\Filament\Concerns\HasRoleAccess;
use App\Filament\Resources\PricingTierResource\Pages;
use App\Filament\Resources\PricingTierResource\RelationManagers\PriceListItemsRelationManager;
use App\Models\PricingTier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PricingTierResource extends Resource
{
    use HasRoleAccess;

    protected static ?string $model = PricingTier::class;
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Customers & Pricing';
    protected static ?string $navigationLabel = 'Pricing Tiers';
    protected static ?int $navigationSort = 1;

    public static function allowedRoles(): array
    {
        return ['admin', 'sales_staff'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            Select::make('customer_type')
                ->options(collect(CustomerType::cases())->mapWithKeys(
                    fn (CustomerType $t) => [$t->value => $t->label()]
                ))
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('customer_type')
                    ->badge()
                    ->formatStateUsing(fn (CustomerType $state) => $state->label())
                    ->colors([
                        'info'    => CustomerType::Household->value,
                        'warning' => CustomerType::Restaurant->value,
                        'success' => CustomerType::Retailer->value,
                    ]),
                TextColumn::make('price_list_items_count')
                    ->counts('priceListItems')
                    ->label('Price Items'),
                TextColumn::make('customers_count')
                    ->counts('customers')
                    ->label('Customers'),
            ])
            ->filters([
                SelectFilter::make('customer_type')
                    ->options(collect(CustomerType::cases())->mapWithKeys(
                        fn (CustomerType $t) => [$t->value => $t->label()]
                    )),
            ])
            ->actions([EditAction::make()])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelationManagers(): array
    {
        return [
            PriceListItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPricingTiers::route('/'),
            'create' => Pages\CreatePricingTier::route('/create'),
            'edit'   => Pages\EditPricingTier::route('/{record}/edit'),
        ];
    }
}
