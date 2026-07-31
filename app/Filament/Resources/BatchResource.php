<?php

namespace App\Filament\Resources;

use App\Enums\StorageLocation;
use App\Filament\Concerns\HasRoleAccess;
use App\Filament\Resources\BatchResource\Pages;
use App\Filament\Resources\BatchResource\RelationManagers\StockMovementsRelationManager;
use App\Models\Batch;
use App\Services\StockService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BatchResource extends Resource
{
    use HasRoleAccess;

    protected static ?string $model = Batch::class;
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';
    protected static ?string $navigationGroup = 'Inventory';
    protected static ?int $navigationSort = 2;

    public static function allowedRoles(): array
    {
        return ['admin', 'warehouse_staff'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('product_id')
                ->relationship('product', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->disabled(fn (?Batch $record) => $record !== null),

            Select::make('supplier_id')
                ->relationship('supplier', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->disabled(fn (?Batch $record) => $record !== null),

            TextInput::make('batch_code')
                ->maxLength(100)
                ->placeholder('Auto-generated if left blank')
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Batch $record) => $record !== null),

            Select::make('storage_location')
                ->options(collect(StorageLocation::cases())->mapWithKeys(
                    fn (StorageLocation $s) => [$s->value => $s->label()]
                ))
                ->required(),

            DatePicker::make('catch_date'),
            DatePicker::make('production_date'),

            DatePicker::make('expiry_date')
                ->required(),

            TextInput::make('unit_cost')
                ->numeric()
                ->prefix('$')
                ->required()
                ->minValue(0),

            TextInput::make('quantity_received')
                ->numeric()
                ->required()
                ->minValue(0.001)
                ->helperText('Initial inbound quantity. Stock is recorded via audit movement on create.')
                ->disabled(fn (?Batch $record) => $record !== null)
                ->dehydrated(),

            TextInput::make('quantity_available')
                ->numeric()
                ->disabled()
                ->dehydrated(false)
                ->visible(fn (?Batch $record) => $record !== null)
                ->helperText('Use Adjust Stock to change available quantity.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch_code')
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                TextColumn::make('product.name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->toggleable(),

                TextColumn::make('expiry_date')
                    ->date()
                    ->sortable()
                    ->color(fn (Batch $record) => match (true) {
                        $record->expiry_date->isPast()            => 'danger',
                        $record->expiry_date->diffInDays() <= 3   => 'warning',
                        default                                   => null,
                    }),

                TextColumn::make('quantity_available')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->color(fn (Batch $record) => (float) $record->quantity_available <= 0 ? 'danger' : null),

                TextColumn::make('storage_location')
                    ->badge()
                    ->formatStateUsing(fn (StorageLocation $state) => $state->label())
                    ->colors([
                        'info'    => StorageLocation::Fresh->value,
                        'primary' => StorageLocation::Chilled->value,
                        'success' => StorageLocation::Frozen->value,
                    ]),

                TextColumn::make('unit_cost')
                    ->money('USD')
                    ->toggleable(),
            ])
            ->defaultSort('expiry_date', 'asc')
            ->filters([
                SelectFilter::make('storage_location')
                    ->options(collect(StorageLocation::cases())->mapWithKeys(
                        fn (StorageLocation $s) => [$s->value => $s->label()]
                    )),
            ])
            ->actions([
                EditAction::make(),

                Action::make('adjust_stock')
                    ->label('Adjust Stock')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('warning')
                    ->form([
                        TextInput::make('quantity')
                            ->numeric()
                            ->required()
                            ->helperText('Use a negative number to remove stock.'),
                        Textarea::make('reason')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (Batch $record, array $data): void {
                        try {
                            app(StockService::class)->recordAdjustment(
                                batch: $record,
                                quantity: (float) $data['quantity'],
                                reason: $data['reason'],
                            );
                            Notification::make()
                                ->title('Stock adjusted successfully')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Adjustment failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelationManagers(): array
    {
        return [
            StockMovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBatches::route('/'),
            'create' => Pages\CreateBatch::route('/create'),
            'edit'   => Pages\EditBatch::route('/{record}/edit'),
        ];
    }
}
