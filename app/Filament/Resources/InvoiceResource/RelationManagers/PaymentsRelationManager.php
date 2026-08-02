<?php

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Enums\PaymentMethod;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';
    protected static ?string $title = 'Payments';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('amount')->formatStateUsing(fn ($state) => \App\Support\Money::format($state)),
                TextColumn::make('payment_method')
                    ->badge()
                    ->formatStateUsing(fn (PaymentMethod $state) => $state->label()),
                TextColumn::make('paid_at')->dateTime(),
                TextColumn::make('recorder.name')->label('Recorded By'),
            ])
            ->defaultSort('paid_at', 'desc')
            ->paginated(false);
    }
}
