<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Allocates stock using FEFO (First-Expired-First-Out).
 * Skips expired batches. Uses row locks when called inside a transaction.
 */
class StockAllocationService
{
    /**
     * @return Collection<int, object{batch: Batch, quantity_to_deduct: float}>
     *
     * @throws \RuntimeException
     */
    public function allocate(Product|int $product, float $quantityNeeded): Collection
    {
        $productId = $product instanceof Product ? $product->id : $product;

        $query = Batch::query()
            ->where('product_id', $productId)
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->orderBy('expiry_date', 'asc')
            ->orderBy('id', 'asc');

        // Lock rows when inside an open DB transaction (e.g. order confirm)
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $batches = $query->get();

        $allocations = collect();
        $remaining = $quantityNeeded;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $deduct = min((float) $batch->quantity_available, $remaining);

            $allocations->push((object) [
                'batch'              => $batch,
                'quantity_to_deduct' => $deduct,
            ]);

            $remaining -= $deduct;
            $remaining = round($remaining, 6);
        }

        if ($remaining > 0) {
            $productName = $product instanceof Product
                ? $product->name
                : Product::find($productId)?->name ?? "ID {$productId}";

            throw new \RuntimeException(
                "Insufficient stock for product \"{$productName}\" "
                . '(non-expired batches only). '
                . "Requested: {$quantityNeeded}, available: "
                . round($quantityNeeded - $remaining, 3) . '.'
            );
        }

        return $allocations;
    }

    public function availableQuantity(Product|int $product): float
    {
        $productId = $product instanceof Product ? $product->id : $product;

        return (float) Batch::query()
            ->where('product_id', $productId)
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->sum('quantity_available');
    }
}
