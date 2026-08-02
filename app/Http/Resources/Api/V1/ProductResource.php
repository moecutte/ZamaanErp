<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'species' => $this->species,
            'category' => $this->category,
            'unit_type' => $this->unit_type?->value,
            'unit_type_label' => $this->unit_type?->label(),
            'description' => $this->description,
            'forms' => $this->whenLoaded('forms', fn () => $this->forms->map(fn ($form) => [
                'id' => $form->id,
                'name' => $form->name,
                'code' => $form->code,
                'is_base' => (bool) $form->is_base,
                'is_active' => (bool) $form->is_active,
            ])),
        ];
    }
}
