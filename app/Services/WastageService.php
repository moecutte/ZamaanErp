<?php

namespace App\Services;

use App\Enums\WastageReason;
use App\Models\Batch;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records wastage/spoilage as a distinct stock movement, separate from sales.
 */
class WastageService
{
    public function __construct(private readonly StockService $stockService) {}

    public function record(
        Batch $batch,
        float $quantity,
        WastageReason $reason,
        ?string $notes = null,
        ?int $createdBy = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Wastage quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($batch, $quantity, $reason, $notes, $createdBy) {
            $reasonText = $reason->label() . ($notes ? ": {$notes}" : '');

            return $this->stockService->recordWastage(
                batch: $batch,
                quantity: $quantity,
                reason: $reasonText,
                createdBy: $createdBy ?? Auth::id(),
            );
        });
    }
}
