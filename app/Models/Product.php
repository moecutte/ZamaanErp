<?php

namespace App\Models;

use App\Enums\UnitType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'species',
        'category',
        'unit_type',
        'sku',
        'description',
        'image',
    ];

    protected $appends = [
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'unit_type' => UnitType::class,
        ];
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return asset('storage/' . ltrim($this->image, '/'));
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(ProductForm::class);
    }

    public function baseForm(): ?ProductForm
    {
        return $this->forms()->where('is_base', true)->first();
    }

    public function priceListItems(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function customerPriceOverrides(): HasMany
    {
        return $this->hasMany(CustomerPriceOverride::class);
    }

    public function salesOrderLines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function processings(): HasMany
    {
        return $this->hasMany(ProductProcessing::class);
    }
}
