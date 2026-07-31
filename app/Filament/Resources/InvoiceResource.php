<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Filament\Concerns\HasRoleAccess;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Resources\InvoiceResource\RelationManagers\PaymentsRelationManager;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    use HasRoleAccess;

    protected static ?string $model = Invoice::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?int $navigationSort = 1;

    public static function allowedRoles(): array
    {
        return ['admin', 'sales_staff'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('invoice_number')->disabled(),
            TextInput::make('sales_order_id')->label('Sales Order #')->disabled(),
            DatePicker::make('issue_date')->disabled(),
            DatePicker::make('due_date')->disabled(),
            TextInput::make('total_amount')->prefix('$')->disabled(),
            TextInput::make('amount_paid')->prefix('$')->disabled(),
            Select::make('status')
                ->options(collect(InvoiceStatus::cases())->mapWithKeys(
                    fn (InvoiceStatus $s) => [$s->value => $s->label()]
                ))
                ->disabled(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')->searchable()->sortable()->copyable(),
                TextColumn::make('salesOrder.customer.name')->label('Customer')->searchable(),
                TextColumn::make('salesOrder.channel')
                    ->label('Channel')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('total_amount')->money('USD')->sortable(),
                TextColumn::make('amount_paid')->money('USD'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                    ->color(fn (InvoiceStatus $state) => match ($state) {
                        InvoiceStatus::Unpaid  => 'warning',
                        InvoiceStatus::Partial => 'info',
                        InvoiceStatus::Paid    => 'success',
                        InvoiceStatus::Overdue => 'danger',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InvoiceStatus::cases())->mapWithKeys(
                        fn (InvoiceStatus $s) => [$s->value => $s->label()]
                    )),
            ])
            ->actions([
                ViewAction::make(),

                Action::make('record_payment')
                    ->label('Record Payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Invoice $record) =>
                        ! in_array($record->status, [InvoiceStatus::Paid], true)
                    )
                    ->form([
                        TextInput::make('amount')
                            ->numeric()
                            ->prefix('$')
                            ->required()
                            ->minValue(0.01)
                            ->default(fn (Invoice $record) =>
                                round((float) $record->total_amount - (float) $record->amount_paid, 2)
                            ),
                        Select::make('payment_method')
                            ->options(collect(PaymentMethod::cases())->mapWithKeys(
                                fn (PaymentMethod $m) => [$m->value => $m->label()]
                            ))
                            ->required()
                            ->default(PaymentMethod::Cash->value),
                        DatePicker::make('paid_at')
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function (Invoice $record, array $data): void {
                        try {
                            app(InvoiceService::class)->applyPayment(
                                invoice: $record,
                                amount: (float) $data['amount'],
                                method: PaymentMethod::from($data['payment_method']),
                                paidAt: $data['paid_at'],
                            );
                            Notification::make()
                                ->title('Payment recorded')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Payment failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getRelationManagers(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'view'  => Pages\ViewInvoice::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // invoices are auto-generated on order confirmation
    }
}
