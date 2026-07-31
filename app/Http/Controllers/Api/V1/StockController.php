<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StockResource;
use App\Models\Batch;
use App\Models\Product;
use App\Services\StockAllocationService;

class StockController extends Controller
{
    public function __construct(private readonly StockAllocationService $allocator) {}

    public function show(Product $product): StockResource
    {
        abort_unless(
            request()->user()?->hasAnyRole(['admin', 'warehouse_staff', 'sales_staff']),
            403
        );

        $batches = Batch::query()
            ->where('product_id', $product->id)
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->orderBy('expiry_date')
            ->get(['id', 'batch_code', 'expiry_date', 'quantity_available', 'storage_location']);

        return new StockResource([
            'product' => $product,
            'available_quantity' => $this->allocator->availableQuantity($product),
            'batches' => $batches,
        ]);
    }
}
