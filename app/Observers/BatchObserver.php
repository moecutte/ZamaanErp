<?php

namespace App\Observers;

use App\Models\Batch;
use Illuminate\Support\Str;

class BatchObserver
{
    /**
     * Auto-generate a unique batch_code before creation if one wasn't provided.
     * Format: BCH-YYYYMMDD-XXXXXXXX  (8 random hex chars)
     */
    public function creating(Batch $batch): void
    {
        if (empty($batch->batch_code)) {
            do {
                $code = 'BCH-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
            } while (Batch::where('batch_code', $code)->exists());

            $batch->batch_code = $code;
        }
    }
}
