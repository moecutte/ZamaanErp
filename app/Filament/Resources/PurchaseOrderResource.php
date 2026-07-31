<?php

namespace App\Filament\Resources;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StorageLocation;
use App\Filament\Concerns\HasRoleAccess;
use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Filament\Resources\PurchaseOrderResource\RelationManagers\LinesRelationManager;
use App\Models\PurchaseOrder;
use App\Services\ReceivePurchaseOrderService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

class PurchaseOrderResource extends Resource
{
    use HasRoleAccess;

    protected static ?string $model = PurchaseOrder::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Purchasing';
    protected static ?int $navigationSort = 1;

    public static function allowedRoles(): array
    {
        return ['admin', 'warehouse_staff'];
    }

    public static function form(Form $form): Form
    {
        $isLocked = fn (?PurchaseOrder $record) =>
            $record && $record->status !== PurchaseOrderStatus::Pending;

        return $form->schema([
            Select::make('supplier_id')
                ->relationship('supplier', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->columnSpan(2)
                ->disabled($isLocked),

            DatePicker::make('order_date')
                ->required()
                ->default(now())
                ->disabled($isLocked),

            Select::make('status')
                ->options(collect(PurchaseOrderStatus::cases())->mapWithKeys(
                    fn (PurchaseOrderStatus $s) => [$s->value => $s->label()]
                ))
                ->default(PurchaseOrderStatus::Pending->value)
                ->disabled()
                ->dehydrated(),

            TextInput::make('total_cost')
                ->numeric()
                ->prefix('$')
                ->default(0)
                ->readOnly(),

            Repeater::make('lines')
                ->relationship()
                ->schema([
                    Select::make('product_id')
                        ->relationship('product', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(2),

                    TextInput::make('quantity')
                        ->numeric()
                        ->required()
                        ->minValue(0.001)
                        ->live()
                        ->afterStateUpdated(fn ($state, callable $get, callable $set) =>
                            $set('../../total_cost',
                                collect($get('../../lines'))
                                    ->sum(fn ($l) => ($l['quantity'] ?? 0) * ($l['unit_cost'] ?? 0))
                            )
                        ),

                    TextInput::make('unit_cost')
                        ->numeric()
                        ->prefix('$')
                        ->required()
                        ->minValue(0)
                        ->live()
                        ->afterStateUpdated(fn ($state, callable $get, callable $set) =>
                            $set('../../total_cost',
                                collect($get('../../lines'))
                                    ->sum(fn ($l) => ($l['quantity'] ?? 0) * ($l['unit_cost'] ?? 0))
                            )
                        ),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->addActionLabel('Add Line')
                ->minItems(1)
                ->disabled($isLocked)
                ->dehydrated(fn (?PurchaseOrder $record) => ! $record || $record->status === PurchaseOrderStatus::Pending),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('PO #')->sortable(),
                TextColumn::make('supplier.name')->searchable()->sortable(),
                TextColumn::make('order_date')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseOrderStatus $state) => $state->label())
                    ->color(fn (PurchaseOrderStatus $state) => match ($state) {
                        PurchaseOrderStatus::Pending   => 'warning',
                        PurchaseOrderStatus::Received  => 'success',
                        PurchaseOrderStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('total_cost')->money('USD')->sortable(),
                TextColumn::make('lines_count')->counts('lines')->label('Lines'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PurchaseOrderStatus::cases())->mapWithKeys(
                        fn (PurchaseOrderStatus $s) => [$s->value => $s->label()]
                    )),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn (PurchaseOrder $record) =>
                        $record->status === PurchaseOrderStatus::Pending
                    ),

                Action::make('receive')
                    ->label('Receive PO')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('success')
                    ->visible(fn (PurchaseOrder $record) =>
                        $record->status === PurchaseOrderStatus::Pending
                    )
                    ->mountUsing(function (\Filament\Forms\ComponentContainer $form, PurchaseOrder $record) {
                        // Pre-fill one entry per PO line so the user can enter batch details
                        $form->fill([
                            'line_details' => $record->lines->map(fn ($line) => [
                                'line_id'          => $line->id,
                                'product_name'     => $line->product->name,
                                'quantity'         => $line->quantity,
                                'expiry_date'      => null,
                                'catch_date'       => null,
                                'production_date'  => null,
                                'storage_location' => StorageLocation::Frozen->value,
                            ])->toArray(),
                        ]);
                    })
                    ->form([
                        Repeater::make('line_details')
                            ->label('Batch details per line')
                            ->schema([
                                TextInput::make('line_id')->hidden(),
                                TextInput::make('product_name')
                                    ->label('Product')
                                    ->readOnly(),
                                TextInput::make('quantity')
                                    ->numeric()
                                    ->readOnly(),
                                DatePicker::make('expiry_date')
                                    ->required(),
                                DatePicker::make('catch_date'),
                                DatePicker::make('production_date'),
                                Select::make('storage_location')
                                    ->options(collect(StorageLocation::cases())->mapWithKeys(
                                        fn (StorageLocation $s) => [$s->value => $s->label()]
                                    ))
                                    ->required(),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ])
                    ->action(function (PurchaseOrder $record, array $data): void {
                        try {
                            $lineDetails = collect($data['line_details'])
                                ->keyBy('line_id')
                                ->toArray();

                            app(ReceivePurchaseOrderService::class)->receive(
                                purchaseOrder: $record,
                                lineDetails: $lineDetails,
                                receivedBy: auth()->id(),
                            );

                            Notification::make()
                                ->title('Purchase order received successfully')
                                ->body('Batches created and stock movements recorded.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Receive failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation(false),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PurchaseOrder $record) =>
                        $record->status === PurchaseOrderStatus::Pending
                    )
                    ->requiresConfirmation()
                    ->action(fn (PurchaseOrder $record) =>
                        $record->update(['status' => PurchaseOrderStatus::Cancelled])
                    ),
            ])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelationManagers(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'edit'   => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
