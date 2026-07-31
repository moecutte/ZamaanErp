<?php

namespace App\Filament\Resources\BatchResource\Pages;

use App\Filament\Resources\BatchResource;
use App\Services\StockService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBatch extends CreateRecord
{
    protected static string $resource = BatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $inboundQty = (float) ($data['quantity_received'] ?? 0);

        $data['quantity_available'] = 0;
        $data['quantity_received'] = $inboundQty;

        $batch = static::getModel()::create($data);

        if ($inboundQty > 0) {
            app(StockService::class)->recordIn(
                batch: $batch,
                quantity: $inboundQty,
                reason: 'Initial batch receipt',
            );
        }

        return $batch->fresh();
    }
}
