<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Central service for all stock mutations.
 *
 * Every change to batch quantities MUST go through this service so that
 * a StockMovement audit record is always created atomically.
 */
class StockService
{
    public function recordIn(
        Batch $batch,
        float $quantity,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $createdBy = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Inbound quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($batch, $quantity, $reference, $reason, $createdBy) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $locked->increment('quantity_available', $quantity);

            return $this->createMovement(
                batch: $locked,
                type: StockMovementType::PurchaseIn,
                quantity: $quantity,
                reference: $reference,
                reason: $reason,
                createdBy: $createdBy,
            );
        });
    }

    public function recordSaleOut(
        Batch $batch,
        float $quantity,
        ?Model $reference = null,
        ?int $createdBy = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Sale quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($batch, $quantity, $reference, $createdBy) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $this->guardSufficientStock($locked, $quantity);
            $locked->decrement('quantity_available', $quantity);

            return $this->createMovement(
                batch: $locked,
                type: StockMovementType::SaleOut,
                quantity: $quantity,
                reference: $reference,
                createdBy: $createdBy,
            );
        });
    }

    public function recordWastage(
        Batch $batch,
        float $quantity,
        string $reason,
        ?Model $reference = null,
        ?int $createdBy = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Wastage quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($batch, $quantity, $reason, $reference, $createdBy) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $this->guardSufficientStock($locked, $quantity);
            $locked->decrement('quantity_available', $quantity);

            return $this->createMovement(
                batch: $locked,
                type: StockMovementType::WastageOut,
                quantity: $quantity,
                reference: $reference,
                reason: $reason,
                createdBy: $createdBy,
            );
        });
    }

    public function recordProcessingOut(
        Batch $batch,
        float $quantity,
        ?Model $reference = null,
        ?int $createdBy = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Processing out quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($batch, $quantity, $reference, $createdBy) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $this->guardSufficientStock($locked, $quantity);
            $locked->decrement('quantity_available', $quantity);

            return $this->createMovement(
                batch: $locked,
                type: StockMovementType::ProcessingOut,
                quantity: $quantity,
                reference: $reference,
                reason: 'Form processing',
                createdBy: $createdBy,
            );
        });
    }

    public function recordProcessingIn(
        Batch $batch,
        float $quantity,
        ?Model $reference = null,
        ?int $createdBy = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Processing in quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($batch, $quantity, $reference, $createdBy) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $locked->increment('quantity_available', $quantity);

            return $this->createMovement(
                batch: $locked,
                type: StockMovementType::ProcessingIn,
                quantity: $quantity,
                reference: $reference,
                reason: 'Form processing',
                createdBy: $createdBy,
            );
        });
    }

    public function recordAdjustment(
        Batch $batch,
        float $quantity,
        string $reason,
        ?int $createdBy = null,
    ): StockMovement {
        if ($quantity == 0.0) {
            throw new \InvalidArgumentException('Adjustment quantity cannot be zero.');
        }

        return DB::transaction(function () use ($batch, $quantity, $reason, $createdBy) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($quantity < 0) {
                $this->guardSufficientStock($locked, abs($quantity));
            }

            $locked->quantity_available = round((float) $locked->quantity_available + $quantity, 6);
            $locked->save();

            return $this->createMovement(
                batch: $locked,
                type: StockMovementType::Adjustment,
                quantity: $quantity,
                reason: $reason,
                createdBy: $createdBy,
            );
        });
    }

    private function guardSufficientStock(Batch $batch, float $quantity): void
    {
        $available = (float) $batch->quantity_available;
        if ($quantity > $available) {
            throw new \RuntimeException(
                "Batch {$batch->batch_code}: requested {$quantity}, only {$available} available."
            );
        }
    }

    private function createMovement(
        Batch $batch,
        StockMovementType $type,
        float $quantity,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $createdBy = null,
    ): StockMovement {
        return StockMovement::create([
            'batch_id'       => $batch->id,
            'type'           => $type,
            'quantity'       => $quantity,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id'   => $reference?->getKey(),
            'reason'         => $reason,
            'created_by'     => $createdBy ?? Auth::id(),
        ]);
    }
}
