<?php

namespace App\Filament\Resources;

use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Filament\Concerns\HasRoleAccess;
use App\Filament\Resources\SalesOrderResource\Pages;
use App\Models\SalesOrder;
use App\Services\CancelSalesOrderService;
use App\Services\ConfirmSalesOrderService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesOrderResource extends Resource
{
    use HasRoleAccess;

    protected static ?string $model = SalesOrder::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?string $navigationLabel = 'All Orders';
    protected static ?int $navigationSort = 3;

    public static function allowedRoles(): array
    {
        return ['admin', 'sales_staff'];
    }

    public static function canDelete($record): bool
    {
        return (auth()->user()?->hasRole('admin') ?? false)
            && $record->status === SalesOrderStatus::Draft;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('customer_id')
                ->relationship('customer', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->disabled(fn (?SalesOrder $record) => $record && $record->status !== SalesOrderStatus::Draft),

            Select::make('channel')
                ->options(collect(SalesChannel::cases())->mapWithKeys(
                    fn (SalesChannel $c) => [$c->value => $c->label()]
                ))
                ->required()
                ->disabled(),

            DatePicker::make('order_date')->required(),

            Select::make('status')
                ->options(collect(SalesOrderStatus::cases())->mapWithKeys(
                    fn (SalesOrderStatus $s) => [$s->value => $s->label()]
                ))
                ->disabled(),

            Toggle::make('delivery_required')
                ->disabled(fn (?SalesOrder $record) => $record && $record->status !== SalesOrderStatus::Draft),

            DatePicker::make('delivery_date')
                ->visible(fn ($get) => (bool) $get('delivery_required'))
                ->disabled(fn (?SalesOrder $record) => $record && $record->status !== SalesOrderStatus::Draft),
        ])->columns(2);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Order details')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id')->label('Order #'),
                        TextEntry::make('channel')
                            ->badge()
                            ->formatStateUsing(fn (SalesChannel $state) => $state->label())
                            ->color(fn (SalesChannel $state) => match ($state) {
                                SalesChannel::Pos => 'info',
                                SalesChannel::SalesOrder => 'success',
                            }),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (SalesOrderStatus $state) => $state->label())
                            ->color(fn (SalesOrderStatus $state) => match ($state) {
                                SalesOrderStatus::Draft     => 'gray',
                                SalesOrderStatus::Confirmed => 'info',
                                SalesOrderStatus::Fulfilled => 'success',
                                SalesOrderStatus::Invoiced  => 'primary',
                                SalesOrderStatus::Cancelled => 'danger',
                            }),
                        TextEntry::make('customer.name')->label('Customer'),
                        TextEntry::make('customer.type')
                            ->label('Customer type')
                            ->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('order_date')->date(),
                        TextEntry::make('delivery_required')
                            ->label('Delivery')
                            ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
                        TextEntry::make('delivery_date')->date()->placeholder('—'),
                        TextEntry::make('creator.name')->label('Created by')->placeholder('—'),
                        TextEntry::make('invoice.invoice_number')->label('Invoice')->placeholder('—'),
                        TextEntry::make('invoice.status')
                            ->label('Invoice status')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->label())
                            ->placeholder('—'),
                        TextEntry::make('invoice.total_amount')
                            ->label('Invoice total')
                            ->formatStateUsing(fn ($state) => \App\Support\Money::format($state))
                            ->placeholder('—'),
                    ]),

                Section::make('Products')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->columns(5)
                            ->schema([
                                TextEntry::make('product.name')->label('Product'),
                                TextEntry::make('product.sku')->label('SKU'),
                                TextEntry::make('batch.batch_code')->label('Batch')->placeholder('—'),
                                TextEntry::make('quantity')->label('Qty')->numeric(decimalPlaces: 3),
                                TextEntry::make('unit_price')->label('Unit price')->formatStateUsing(fn ($state) => \App\Support\Money::format($state)),
                                TextEntry::make('subtotal')->label('Subtotal')->formatStateUsing(fn ($state) => \App\Support\Money::format($state)),
                            ]),
                        TextEntry::make('lines_total')
                            ->label('Order total')
                            ->state(fn (SalesOrder $record) => round((float) $record->lines->sum('subtotal'), 2))
                            ->formatStateUsing(fn ($state) => \App\Support\Money::format($state))
                            ->weight('bold'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Order #')->sortable(),
                TextColumn::make('customer.name')->searchable()->sortable(),
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn (SalesChannel $state) => $state->label())
                    ->colors([
                        'info' => SalesChannel::Pos->value,
                        'success' => SalesChannel::SalesOrder->value,
                    ]),
                TextColumn::make('order_date')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SalesOrderStatus $state) => $state->label())
                    ->color(fn (SalesOrderStatus $state) => match ($state) {
                        SalesOrderStatus::Draft     => 'gray',
                        SalesOrderStatus::Confirmed => 'info',
                        SalesOrderStatus::Fulfilled => 'success',
                        SalesOrderStatus::Invoiced  => 'primary',
                        SalesOrderStatus::Cancelled => 'danger',
                    }),
                IconColumn::make('delivery_required')->boolean()->label('Delivery'),
                TextColumn::make('lines_count')->counts('lines')->label('Lines'),
                TextColumn::make('creator.name')->label('By')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('channel')
                    ->options(collect(SalesChannel::cases())->mapWithKeys(
                        fn (SalesChannel $c) => [$c->value => $c->label()]
                    )),
                SelectFilter::make('status')
                    ->options(collect(SalesOrderStatus::cases())->mapWithKeys(
                        fn (SalesOrderStatus $s) => [$s->value => $s->label()]
                    )),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (SalesOrder $record) => $record->status === SalesOrderStatus::Draft),

                Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SalesOrder $record) => $record->status === SalesOrderStatus::Draft)
                    ->requiresConfirmation()
                    ->modalHeading('Confirm Sales Order')
                    ->modalDescription('This will allocate stock via FEFO, create invoice, and optional delivery.')
                    ->action(function (SalesOrder $record): void {
                        try {
                            app(ConfirmSalesOrderService::class)->confirm($record);
                            Notification::make()
                                ->title('Order confirmed')
                                ->body('Stock allocated via FEFO and invoice generated.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Confirmation failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (SalesOrder $record) =>
                        $record->status !== SalesOrderStatus::Cancelled
                        && (! $record->invoice || (float) $record->invoice->amount_paid === 0.0)
                    )
                    ->form([
                        Textarea::make('reason')->label('Reason')->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->action(function (SalesOrder $record, array $data): void {
                        try {
                            app(CancelSalesOrderService::class)->cancel(
                                $record,
                                $data['reason'] ?? null,
                            );
                            Notification::make()
                                ->title('Order cancelled')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Cancel failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesOrders::route('/'),
            'view'  => Pages\ViewSalesOrder::route('/{record}'),
            'edit'  => Pages\EditSalesOrder::route('/{record}/edit'),
        ];
    }
}
