<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method?->value,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'total_amount' => (float) $this->invoice->total_amount,
                'amount_paid' => (float) $this->invoice->amount_paid,
                'status' => $this->invoice->status?->value,
            ]),
        ];
    }
}
