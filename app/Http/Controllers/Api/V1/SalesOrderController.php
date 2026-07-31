<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSalesOrderRequest;
use App\Http\Resources\Api\V1\SalesOrderResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\ConfirmSalesOrderService;
use App\Services\PricingResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller
{
    public function __construct(
        private readonly PricingResolutionService $pricing,
        private readonly ConfirmSalesOrderService $confirmService,
    ) {}

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $customer = Customer::findOrFail($data['customer_id']);
        $channel = SalesChannel::from($data['channel']);
        $autoConfirm = (bool) ($data['confirm'] ?? ($channel === SalesChannel::Pos));

        try {
            $order = DB::transaction(function () use ($data, $customer, $channel, $autoConfirm, $request) {
                $order = SalesOrder::create([
                    'customer_id'       => $customer->id,
                    'channel'           => $channel,
                    'order_date'        => $data['order_date'] ?? now()->toDateString(),
                    'status'            => SalesOrderStatus::Draft,
                    'delivery_required' => $data['delivery_required'] ?? false,
                    'delivery_date'     => $data['delivery_date'] ?? null,
                    'created_by'        => $request->user()->id,
                ]);

                foreach ($data['lines'] as $line) {
                    $product = Product::findOrFail($line['product_id']);
                    $qty = (float) $line['quantity'];
                    // Always resolve server-side — never trust client unit_price
                    $unitPrice = $this->pricing->resolveOrFail($customer, $product, $qty);

                    $order->lines()->create([
                        'product_id' => $product->id,
                        'batch_id'   => null,
                        'quantity'   => $qty,
                        'unit_price' => $unitPrice,
                        'subtotal'   => round($qty * $unitPrice, 2),
                    ]);
                }

                if ($autoConfirm) {
                    $order = $this->confirmService->confirm($order);
                }

                return $order->fresh(['lines.product', 'lines.batch', 'customer', 'invoice']);
            });

            return (new SalesOrderResource($order))
                ->response()
                ->setStatusCode(201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(SalesOrder $salesOrder): SalesOrderResource
    {
        abort_unless(
            request()->user()?->hasAnyRole(['admin', 'sales_staff']),
            403
        );

        $salesOrder->load(['lines.product', 'lines.batch', 'customer', 'invoice']);

        return new SalesOrderResource($salesOrder);
    }
}
