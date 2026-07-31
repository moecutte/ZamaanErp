<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Batch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;

/**
 * Processes "Receive PO" — for each line on the purchase order:
 *   1. Creates a Batch record (or reuses one if already linked).
 *   2. Links the batch back to the PO line.
 *   3. Records a purchase_in StockMovement via StockService.
 *   4. Marks the PurchaseOrder as received.
 *
 * The entire operation is wrapped in a single DB transaction.
 */
class ReceivePurchaseOrderService
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * @param  PurchaseOrder  $purchaseOrder
     * @param  array<int, array{
     *     catch_date: string|null,
     *     production_date: string|null,
     *     expiry_date: string,
     *     storage_location: string,
     *     unit_cost: float,
     * }>  $lineDetails   Keyed by PurchaseOrderLine->id
     * @param  int  $receivedBy  user id
     */
    public function receive(
        PurchaseOrder $purchaseOrder,
        array $lineDetails,
        int $receivedBy,
    ): void {
        if ($purchaseOrder->status === PurchaseOrderStatus::Received) {
            throw new \RuntimeException('This purchase order has already been received.');
        }

        if ($purchaseOrder->status === PurchaseOrderStatus::Cancelled) {
            throw new \RuntimeException('Cannot receive a cancelled purchase order.');
        }

        DB::transaction(function () use ($purchaseOrder, $lineDetails, $receivedBy) {
            // Re-check under lock to prevent double-receive races
            $locked = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PurchaseOrderStatus::Received) {
                throw new \RuntimeException('This purchase order has already been received.');
            }

            foreach ($purchaseOrder->lines()->with('product')->get() as $line) {
                $details = $lineDetails[$line->id] ?? [];

                // Create with quantity_available = 0; StockService::recordIn
                // will increment it and log the movement atomically.
                $batch = Batch::create([
                    'product_id'         => $line->product_id,
                    'supplier_id'        => $purchaseOrder->supplier_id,
                    'catch_date'         => $details['catch_date'] ?? null,
                    'production_date'    => $details['production_date'] ?? null,
                    'expiry_date'        => $details['expiry_date'],
                    'quantity_received'  => (float) $line->quantity,
                    'quantity_available' => 0,
                    'storage_location'   => $details['storage_location'],
                    'unit_cost'          => (float) $line->unit_cost,
                ]);

                // Link batch back to the PO line
                $line->update(['batch_id' => $batch->id]);

                // Record the stock-in movement
                $this->stockService->recordIn(
                    batch: $batch,
                    quantity: (float) $line->quantity,
                    reference: $purchaseOrder,
                    createdBy: $receivedBy,
                );
            }

            $locked->update(['status' => PurchaseOrderStatus::Received]);
        });
    }
}
