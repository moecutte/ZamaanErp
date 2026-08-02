<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'pricing_tier_id',
        'product_id',
        'product_form_id',
        'price_per_unit',
        'min_quantity',
    ];

    protected function casts(): array
    {
        return [
            'price_per_unit' => 'decimal:2',
            'min_quantity' => 'decimal:3',
        ];
    }

    public function pricingTier(): BelongsTo
    {
        return $this->belongsTo(PricingTier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productForm(): BelongsTo
    {
        return $this->belongsTo(ProductForm::class);
    }
}
