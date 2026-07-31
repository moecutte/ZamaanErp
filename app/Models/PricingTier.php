<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'customer_type',
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
        ];
    }

    public function priceListItems(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
