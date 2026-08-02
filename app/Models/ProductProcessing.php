<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductProcessing extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'source_batch_id',
        'source_quantity',
        'waste_quantity',
        'notes',
        'processed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'source_quantity' => 'decimal:3',
            'waste_quantity' => 'decimal:3',
            'processed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'source_batch_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ProductProcessingOutput::class);
    }
}
