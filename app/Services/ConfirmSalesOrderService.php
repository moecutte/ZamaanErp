<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Confirms a draft sales order with FEFO allocation, invoice, and optional delivery.
 */
class ConfirmSalesOrderService
{
    public function __construct(
        private readonly StockAllocationService $allocator,
        private readonly StockService $stockService,
        private readonly InvoiceService $invoiceService,
        private readonly DeliveryService $deliveryService,
    ) {}

    public function confirm(SalesOrder $order, ?PaymentMethod $retailPaymentMethod = PaymentMethod::Cash): SalesOrder
    {
        if ($order->lines()->count() === 0) {
            throw new \RuntimeException('Cannot confirm an order with no lines.');
        }

        return DB::transaction(function () use ($order, $retailPaymentMethod) {
            $order = SalesOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== SalesOrderStatus::Draft) {
                throw new \RuntimeException(
                    "Only draft orders can be confirmed (current status: {$order->status->value})."
                );
            }

            $order->load(['lines.product', 'lines.productForm', 'customer']);

            if ($order->lines->isEmpty()) {
                throw new \RuntimeException('Cannot confirm an order with no lines.');
            }

            $this->assertCreditLimit($order);

            foreach ($order->lines as $line) {
                if ($line->batch_id !== null) {
                    throw new \RuntimeException(
                        'Order line #' . $line->id . ' already has a batch assigned. '
                        . 'Reset the draft lines before confirming.'
                    );
                }

                if ($line->product_form_id === null) {
                    $baseForm = $line->product->baseForm();
                    if ($baseForm === null) {
                        throw new \RuntimeException(
                            'Order line #' . $line->id . ' has no product form and product has no base form.'
                        );
                    }
                    $line->product_form_id = $baseForm->id;
                }

                $allocations = $this->allocator->allocate(
                    $line->product,
                    (float) $line->quantity,
                    $line->product_form_id,
                );

                $line->delete();

                foreach ($allocations as $allocation) {
                    $unitPrice = (float) $line->unit_price;
                    $qty = (float) $allocation->quantity_to_deduct;

                    SalesOrderLine::create([
                        'sales_order_id'   => $order->id,
                        'product_id'       => $line->product_id,
                        'product_form_id'  => $line->product_form_id ?? $allocation->batch->product_form_id,
                        'batch_id'         => $allocation->batch->id,
                        'quantity'         => $qty,
                        'unit_price'       => $unitPrice,
                        'subtotal'         => round($qty * $unitPrice, 2),
                    ]);

                    $this->stockService->recordSaleOut(
                        batch: $allocation->batch,
                        quantity: $qty,
                        reference: $order,
                        createdBy: Auth::id() ?? $order->created_by,
                    );
                }
            }

            $order->update([
                'status' => $order->channel === SalesChannel::Pos
                    ? SalesOrderStatus::Fulfilled
                    : SalesOrderStatus::Confirmed,
            ]);

            $this->invoiceService->generateForOrder(
                $order->fresh(['lines', 'customer']),
                $retailPaymentMethod,
            );

            $order = $order->fresh(['customer']);
            if ($order->delivery_required) {
                $this->deliveryService->createForOrder($order);
            }

            return $order->fresh(['lines', 'customer', 'invoice', 'delivery']);
        });
    }

    private function assertCreditLimit(SalesOrder $order): void
    {
        if ($order->channel === SalesChannel::Pos) {
            return;
        }

        $customer = $order->customer;
        if ($customer->credit_limit === null) {
            return;
        }

        // Serialize credit checks for this customer within the confirm transaction
        DB::table('customers')->where('id', $customer->id)->lockForUpdate()->first();

        $orderTotal = round((float) $order->lines->sum('subtotal'), 2);
        $outstanding = $this->invoiceService->outstandingBalance($customer);
        $projected = round($outstanding + $orderTotal, 2);
        $limit = (float) $customer->credit_limit;

        if ($projected > $limit) {
            throw new \RuntimeException(
                "Credit limit exceeded for \"{$customer->name}\". "
                . "Limit: {$limit}, outstanding: {$outstanding}, this order: {$orderTotal}."
            );
        }
    }
}
