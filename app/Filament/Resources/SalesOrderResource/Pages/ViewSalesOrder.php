<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesOrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesOrder extends ViewRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn () => $this->record->status === SalesOrderStatus::Draft),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load([
            'customer',
            'creator',
            'invoice',
            'lines.product',
            'lines.batch',
        ]);
    }
}
