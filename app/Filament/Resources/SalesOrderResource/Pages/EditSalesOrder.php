<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless(
            $this->getRecord()->status === SalesOrderStatus::Draft,
            403,
            'Only draft orders can be edited.'
        );
    }
}
