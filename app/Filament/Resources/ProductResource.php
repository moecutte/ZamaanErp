<?php

namespace App\Filament\Resources;

use App\Enums\UnitType;
use App\Filament\Concerns\HasRoleAccess;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    use HasRoleAccess;

    protected static ?string $model = Product::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Inventory';
    protected static ?int $navigationSort = 1;

    public static function allowedRoles(): array
    {
        return ['admin', 'warehouse_staff', 'sales_staff'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('sku')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(100),

            TextInput::make('species')
                ->maxLength(100),

            TextInput::make('category')
                ->maxLength(100),

            Select::make('unit_type')
                ->options(collect(UnitType::cases())->mapWithKeys(
                    fn (UnitType $u) => [$u->value => $u->label()]
                ))
                ->required(),

            Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('sku')->searchable()->sortable()->copyable(),
                TextColumn::make('species')->toggleable(),
                TextColumn::make('category')->toggleable(),
                TextColumn::make('unit_type')
                    ->badge()
                    ->formatStateUsing(fn (UnitType $state) => $state->label()),
                TextColumn::make('batches_count')
                    ->counts('batches')
                    ->label('Batches')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('unit_type')
                    ->options(collect(UnitType::cases())->mapWithKeys(
                        fn (UnitType $u) => [$u->value => $u->label()]
                    )),
            ])
            ->actions([EditAction::make()])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\ProductResource\RelationManagers\FormsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
