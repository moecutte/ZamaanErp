<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductProcessingOutput extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_processing_id',
        'product_form_id',
        'quantity',
        'output_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    public function processing(): BelongsTo
    {
        return $this->belongsTo(ProductProcessing::class, 'product_processing_id');
    }

    public function productForm(): BelongsTo
    {
        return $this->belongsTo(ProductForm::class);
    }

    public function outputBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'output_batch_id');
    }
}
