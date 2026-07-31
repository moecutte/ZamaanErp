<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(
            $request->user()?->hasAnyRole(['admin', 'warehouse_staff', 'sales_staff', 'delivery_staff']),
            403
        );

        $query = Product::query()->orderBy('name');

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('species', 'like', "%{$search}%");
            });
        }

        return ProductResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Product $product): ProductResource
    {
        abort_unless(
            request()->user()?->hasAnyRole(['admin', 'warehouse_staff', 'sales_staff', 'delivery_staff']),
            403
        );

        return new ProductResource($product);
    }
}
