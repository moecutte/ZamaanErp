<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SalesChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['admin', 'sales_staff']) ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'channel' => ['required', Rule::enum(SalesChannel::class)],
            'order_date' => ['nullable', 'date'],
            'delivery_required' => ['nullable', 'boolean'],
            'delivery_date' => ['nullable', 'date', 'required_if:delivery_required,true'],
            'confirm' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.product_form_id' => ['nullable', 'exists:product_forms,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
