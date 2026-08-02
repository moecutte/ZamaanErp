<?php

namespace App\Models;

use App\Enums\StorageLocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_form_id',
        'batch_code',
        'supplier_id',
        'catch_date',
        'production_date',
        'expiry_date',
        'quantity_received',
        'quantity_available',
        'storage_location',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'storage_location' => StorageLocation::class,
            'catch_date' => 'date',
            'production_date' => 'date',
            'expiry_date' => 'date',
            'quantity_received' => 'decimal:3',
            'quantity_available' => 'decimal:3',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productForm(): BelongsTo
    {
        return $this->belongsTo(ProductForm::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function salesOrderLines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }
}
