<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;

/**
 * Resolves the correct unit price for a given customer + product + quantity.
 *
 * Resolution order (first match wins):
 *   1. Customer-specific price override (negotiated rate, ignores quantity).
 *   2. Best price from the customer's pricing tier that satisfies min_quantity
 *      (quantity-break pricing: pick the tier entry with the highest min_quantity
 *       that is still ≤ the ordered quantity — gives the best applicable break).
 *   3. null — caller must decide how to handle an unpriced product.
 */
class PricingResolutionService
{
    /**
     * @return float|null  Unit price, or null if no price is configured.
     */
    public function resolve(Customer $customer, Product $product, float $quantity = 0): ?float
    {
        // 1. Customer-specific override
        $override = $customer->priceOverrides()
            ->where('product_id', $product->id)
            ->first();

        if ($override !== null) {
            return (float) $override->price_per_unit;
        }

        // 2. Tier price list with quantity-break logic
        $tier = $customer->pricingTier;

        if ($tier === null) {
            return null;
        }

        $tierPrice = $tier->priceListItems()
            ->where('product_id', $product->id)
            ->where('min_quantity', '<=', $quantity)
            ->orderByDesc('min_quantity') // highest qualifying break = best price
            ->first();

        if ($tierPrice !== null) {
            return (float) $tierPrice->price_per_unit;
        }

        return null;
    }

    /**
     * Convenience: resolve and throw if no price is found.
     *
     * @throws \RuntimeException
     */
    public function resolveOrFail(Customer $customer, Product $product, float $quantity = 0): float
    {
        $price = $this->resolve($customer, $product, $quantity);

        if ($price === null) {
            throw new \RuntimeException(
                "No price configured for customer \"{$customer->name}\" "
                . "and product \"{$product->name}\"."
            );
        }

        return $price;
    }
}
