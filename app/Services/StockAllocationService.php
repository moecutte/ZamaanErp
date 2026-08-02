<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductForm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Allocates stock using FEFO (First-Expired-First-Out).
 * Skips expired batches. Uses row locks when called inside a transaction.
 * Optionally scopes to a product form (whole / steak / fillet).
 */
class StockAllocationService
{
    /**
     * @return Collection<int, object{batch: Batch, quantity_to_deduct: float}>
     *
     * @throws \RuntimeException
     */
    public function allocate(
        Product|int $product,
        float $quantityNeeded,
        ProductForm|int|null $productForm = null,
    ): Collection {
        $productId = $product instanceof Product ? $product->id : $product;
        $formId = $this->resolveFormId($productId, $productForm);

        $query = Batch::query()
            ->where('product_id', $productId)
            ->where('product_form_id', $formId)
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

            $formName = ProductForm::find($formId)?->name ?? 'form';

            throw new \RuntimeException(
                "Insufficient stock for product \"{$productName}\" ({$formName}) "
                . '(non-expired batches only). '
                . "Requested: {$quantityNeeded}, available: "
                . round($quantityNeeded - $remaining, 3) . '.'
            );
        }

        return $allocations;
    }

    public function availableQuantity(
        Product|int $product,
        ProductForm|int|null $productForm = null,
    ): float {
        $productId = $product instanceof Product ? $product->id : $product;
        $formId = $this->resolveFormId($productId, $productForm);

        return (float) Batch::query()
            ->where('product_id', $productId)
            ->where('product_form_id', $formId)
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->sum('quantity_available');
    }

    private function resolveFormId(int $productId, ProductForm|int|null $productForm): int
    {
        if ($productForm instanceof ProductForm) {
            return $productForm->id;
        }

        if (is_int($productForm) && $productForm > 0) {
            return $productForm;
        }

        $baseFormId = ProductForm::query()
            ->where('product_id', $productId)
            ->where('is_base', true)
            ->value('id');

        if (! $baseFormId) {
            throw new \RuntimeException("Product {$productId} has no base form configured.");
        }

        return (int) $baseFormId;
    }
}
