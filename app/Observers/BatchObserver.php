<?php

namespace App\Observers;

use App\Models\Batch;
use App\Models\ProductForm;
use Illuminate\Support\Str;

class BatchObserver
{
    /**
     * Auto-generate a unique batch_code before creation if one wasn't provided.
     * Format: BCH-YYYYMMDD-XXXXXXXX  (8 random hex chars)
     * Default product_form_id to the product's base form when omitted.
     */
    public function creating(Batch $batch): void
    {
        if (empty($batch->batch_code)) {
            do {
                $code = 'BCH-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
            } while (Batch::where('batch_code', $code)->exists());

            $batch->batch_code = $code;
        }

        if (empty($batch->product_form_id) && ! empty($batch->product_id)) {
            $baseFormId = ProductForm::query()
                ->where('product_id', $batch->product_id)
                ->where('is_base', true)
                ->value('id');

            if ($baseFormId) {
                $batch->product_form_id = $baseFormId;
            }
        }
    }
}
