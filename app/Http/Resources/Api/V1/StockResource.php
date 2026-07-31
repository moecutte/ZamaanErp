<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'product' => new ProductResource($data['product']),
            'available_quantity' => (float) $data['available_quantity'],
            'batches' => collect($data['batches'])->map(fn ($b) => [
                'id' => $b->id,
                'batch_code' => $b->batch_code,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'quantity_available' => (float) $b->quantity_available,
                'storage_location' => $b->storage_location?->value,
            ])->values(),
        ];
    }
}
