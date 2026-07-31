<?php

namespace App\Filament\Resources;

use App\Enums\SupplierType;
use App\Filament\Concerns\HasRoleAccess;
use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    use HasRoleAccess;

    protected static ?string $model = Supplier::class;
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Inventory';
    protected static ?int $navigationSort = 3;

    public static function allowedRoles(): array
    {
        return ['admin', 'warehouse_staff'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->columnSpan(2),

            Select::make('type')
                ->options(collect(SupplierType::cases())->mapWithKeys(
                    fn (SupplierType $t) => [$t->value => $t->label()]
                ))
                ->required(),

            TextInput::make('contact_phone')
                ->tel()
                ->maxLength(50),

            TextInput::make('contact_email')
                ->email()
                ->maxLength(255),

            Textarea::make('address')
                ->rows(2)
                ->columnSpanFull(),

            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (SupplierType $state) => $state->label())
                    ->colors([
                        'info'    => SupplierType::Fisherman->value,
                        'success' => SupplierType::Company->value,
                        'warning' => SupplierType::Import->value,
                    ]),
                TextColumn::make('contact_phone')->label('Phone'),
                TextColumn::make('contact_email')->label('Email')->toggleable(),
                TextColumn::make('batches_count')
                    ->counts('batches')
                    ->label('Batches')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(SupplierType::cases())->mapWithKeys(
                        fn (SupplierType $t) => [$t->value => $t->label()]
                    )),
            ])
            ->actions([EditAction::make()])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit'   => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
