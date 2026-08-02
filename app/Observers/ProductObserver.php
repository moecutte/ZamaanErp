<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductForm;

class ProductObserver
{
    public function created(Product $product): void
    {
        if ($product->forms()->where('is_base', true)->exists()) {
            return;
        }

        ProductForm::create([
            'product_id' => $product->id,
            'name' => 'Whole',
            'code' => 'whole',
            'is_base' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
