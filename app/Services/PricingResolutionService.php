<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductForm;

/**
 * Resolves the correct unit price for a given customer + product + form + quantity.
 *
 * Resolution order (first match wins):
 *   1. Customer override for product+form, else product-level override (null form).
 *   2. Tier price for product+form, else product-level / base-form price list entry.
 *   3. null — caller must decide how to handle an unpriced product.
 */
class PricingResolutionService
{
    /**
     * @return float|null  Unit price, or null if no price is configured.
     */
    public function resolve(
        Customer $customer,
        Product $product,
        float $quantity = 0,
        ProductForm|int|null $productForm = null,
    ): ?float {
        $formId = $this->resolveFormId($product, $productForm);

        // 1. Customer-specific override (form-specific first, then product-level)
        $overrideQuery = $customer->priceOverrides()->where('product_id', $product->id);

        $override = (clone $overrideQuery)
            ->where('product_form_id', $formId)
            ->first()
            ?? (clone $overrideQuery)->whereNull('product_form_id')->first();

        if ($override !== null) {
            return (float) $override->price_per_unit;
        }

        // 2. Tier price list with quantity-break logic
        $tier = $customer->pricingTier;

        if ($tier === null) {
            return null;
        }

        $baseQuery = $tier->priceListItems()
            ->where('product_id', $product->id)
            ->where('min_quantity', '<=', $quantity)
            ->orderByDesc('min_quantity');

        $tierPrice = (clone $baseQuery)
            ->where('product_form_id', $formId)
            ->first()
            ?? (clone $baseQuery)->whereNull('product_form_id')->first();

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
    public function resolveOrFail(
        Customer $customer,
        Product $product,
        float $quantity = 0,
        ProductForm|int|null $productForm = null,
    ): float {
        $price = $this->resolve($customer, $product, $quantity, $productForm);

        if ($price === null) {
            $formName = null;
            if ($productForm instanceof ProductForm) {
                $formName = $productForm->name;
            } elseif (is_int($productForm)) {
                $formName = ProductForm::find($productForm)?->name;
            }

            $label = $formName
                ? "{$product->name} ({$formName})"
                : $product->name;

            throw new \RuntimeException(
                "No price configured for customer \"{$customer->name}\" "
                . "and product \"{$label}\"."
            );
        }

        return $price;
    }

    private function resolveFormId(Product $product, ProductForm|int|null $productForm): ?int
    {
        if ($productForm instanceof ProductForm) {
            return $productForm->id;
        }

        if (is_int($productForm) && $productForm > 0) {
            return $productForm;
        }

        return $product->baseForm()?->id;
    }
}
