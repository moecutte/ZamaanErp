<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a sales order and restores stock for any confirmed sale lines.
 */
class CancelSalesOrderService
{
    public function __construct(private readonly StockService $stockService) {}

    public function cancel(SalesOrder $order, ?string $reason = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = SalesOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === SalesOrderStatus::Cancelled) {
                throw new \RuntimeException('Order is already cancelled.');
            }

            if ($order->status === SalesOrderStatus::Draft) {
                $order->update(['status' => SalesOrderStatus::Cancelled]);

                return $order->fresh();
            }

            $order->load(['lines.batch', 'invoice', 'delivery']);

            if ($order->invoice && (float) $order->invoice->amount_paid > 0) {
                throw new \RuntimeException(
                    'Cannot cancel an order with payments recorded. Reverse payments first.'
                );
            }

            foreach ($order->lines as $line) {
                if ($line->batch_id === null) {
                    continue;
                }

                $this->stockService->recordAdjustment(
                    batch: $line->batch,
                    quantity: (float) $line->quantity,
                    reason: 'Order #' . $order->id . ' cancelled'
                        . ($reason ? ": {$reason}" : ''),
                    createdBy: Auth::id() ?? $order->created_by,
                );
            }

            if ($order->invoice) {
                $order->invoice->update([
                    'status' => InvoiceStatus::Cancelled,
                ]);
            }

            if ($order->delivery
                && ! in_array($order->delivery->status, [DeliveryStatus::Delivered, DeliveryStatus::Cancelled], true)
            ) {
                $order->delivery->update([
                    'status' => DeliveryStatus::Cancelled,
                    'notes' => trim(($order->delivery->notes ? $order->delivery->notes . "\n" : '')
                        . 'Cancelled with order #' . $order->id
                        . ($reason ? ": {$reason}" : '')),
                ]);
            }

            $order->update(['status' => SalesOrderStatus::Cancelled]);

            return $order->fresh(['lines', 'invoice', 'delivery']);
        });
    }
}
