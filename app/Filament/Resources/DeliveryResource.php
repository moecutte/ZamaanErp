<?php

namespace App\Filament\Resources;

use App\Enums\DeliveryStatus;
use App\Filament\Concerns\HasRoleAccess;
use App\Filament\Resources\DeliveryResource\Pages;
use App\Models\Delivery;
use App\Models\User;
use App\Services\DeliveryService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeliveryResource extends Resource
{
    use HasRoleAccess;

    protected static ?string $model = Delivery::class;
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int $navigationSort = 4;

    public static function allowedRoles(): array
    {
        return ['admin', 'delivery_staff', 'sales_staff'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('sales_order_id')
                ->relationship('salesOrder', 'id')
                ->getOptionLabelFromRecordUsing(fn ($record) =>
                    "Order #{$record->id} — {$record->customer?->name}"
                )
                ->searchable()
                ->preload()
                ->required()
                ->disabled(fn (?Delivery $record) => $record !== null),

            Select::make('delivery_staff_id')
                ->label('Delivery Staff')
                ->options(function () {
                    return User::role('delivery_staff')->pluck('name', 'id');
                })
                ->searchable()
                ->nullable(),

            DatePicker::make('delivery_date')->required(),

            Select::make('status')
                ->options(collect(DeliveryStatus::cases())->mapWithKeys(
                    fn (DeliveryStatus $s) => [$s->value => $s->label()]
                ))
                ->required()
                ->default(DeliveryStatus::Pending->value),

            Textarea::make('address')->rows(2)->columnSpanFull(),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('sales_order_id')->label('Order #')->sortable(),
                TextColumn::make('salesOrder.customer.name')->label('Customer')->searchable(),
                TextColumn::make('deliveryStaff.name')
                    ->label('Staff')
                    ->placeholder('Unassigned'),
                TextColumn::make('delivery_date')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DeliveryStatus $state) => $state->label())
                    ->color(fn (DeliveryStatus $state) => match ($state) {
                        DeliveryStatus::Pending   => 'warning',
                        DeliveryStatus::InTransit => 'info',
                        DeliveryStatus::Delivered => 'success',
                        DeliveryStatus::Failed    => 'danger',
                        DeliveryStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('address')->limit(30)->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(DeliveryStatus::cases())->mapWithKeys(
                        fn (DeliveryStatus $s) => [$s->value => $s->label()]
                    )),
            ])
            ->actions([
                EditAction::make(),

                Action::make('assign_staff')
                    ->label('Assign Staff')
                    ->icon('heroicon-o-user-plus')
                    ->form([
                        Select::make('delivery_staff_id')
                            ->label('Delivery Staff')
                            ->options(fn () => User::role('delivery_staff')->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (Delivery $record, array $data): void {
                        $staff = User::findOrFail($data['delivery_staff_id']);
                        app(DeliveryService::class)->assignStaff($record, $staff);
                        Notification::make()->title('Staff assigned')->success()->send();
                    }),

                Action::make('mark_in_transit')
                    ->label('In Transit')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->visible(fn (Delivery $r) => $r->status === DeliveryStatus::Pending)
                    ->action(fn (Delivery $r) =>
                        app(DeliveryService::class)->updateStatus($r, DeliveryStatus::InTransit)
                    ),

                Action::make('mark_delivered')
                    ->label('Delivered')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Delivery $r) =>
                        in_array($r->status, [DeliveryStatus::Pending, DeliveryStatus::InTransit], true)
                    )
                    ->requiresConfirmation()
                    ->action(fn (Delivery $r) =>
                        app(DeliveryService::class)->updateStatus($r, DeliveryStatus::Delivered)
                    ),

                Action::make('mark_failed')
                    ->label('Failed')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Delivery $r) =>
                        in_array($r->status, [DeliveryStatus::Pending, DeliveryStatus::InTransit], true)
                    )
                    ->requiresConfirmation()
                    ->action(fn (Delivery $r) =>
                        app(DeliveryService::class)->updateStatus($r, DeliveryStatus::Failed)
                    ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDeliveries::route('/'),
            'create' => Pages\CreateDelivery::route('/create'),
            'edit'   => Pages\EditDelivery::route('/{record}/edit'),
        ];
    }
}
