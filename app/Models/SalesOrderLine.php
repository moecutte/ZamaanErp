<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_order_id',
        'product_id',
        'product_form_id',
        'batch_id',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SalesOrderLine $line) {
            if (empty($line->product_form_id) && ! empty($line->product_id)) {
                $baseFormId = ProductForm::query()
                    ->where('product_id', $line->product_id)
                    ->where('is_base', true)
                    ->value('id');

                if ($baseFormId) {
                    $line->product_form_id = $baseFormId;
                }
            }
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productForm(): BelongsTo
    {
        return $this->belongsTo(ProductForm::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
