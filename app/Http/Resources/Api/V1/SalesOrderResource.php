<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'type' => $this->customer->type?->value,
            ]),
            'channel' => $this->channel?->value,
            'order_date' => $this->order_date?->toDateString(),
            'status' => $this->status?->value,
            'delivery_required' => $this->delivery_required,
            'delivery_date' => $this->delivery_date?->toDateString(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product?->name,
                'batch_id' => $line->batch_id,
                'batch_code' => $line->batch?->batch_code,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'subtotal' => (float) $line->subtotal,
            ])),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'total_amount' => (float) $this->invoice->total_amount,
                'amount_paid' => (float) $this->invoice->amount_paid,
                'status' => $this->invoice->status?->value,
            ] : null),
            'total' => $this->whenLoaded('lines', fn () => round((float) $this->lines->sum('subtotal'), 2)),
        ];
    }
}
