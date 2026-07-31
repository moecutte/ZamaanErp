<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type?->value,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'address' => $this->address,
            'credit_limit' => $this->credit_limit !== null ? (float) $this->credit_limit : null,
            'payment_terms_days' => $this->payment_terms_days,
            'pricing_tier_id' => $this->pricing_tier_id,
        ];
    }
}
