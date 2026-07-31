<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates deliveries for sales orders that require delivery.
 */
class DeliveryService
{
    /**
     * Create a pending delivery for an order (idempotent).
     */
    public function createForOrder(
        SalesOrder $order,
        ?string $address = null,
        ?string $deliveryDate = null,
        ?int $deliveryStaffId = null,
        ?string $notes = null,
    ): Delivery {
        if ($order->delivery()->exists()) {
            return $order->delivery;
        }

        if (! $order->delivery_required) {
            throw new \RuntimeException('This sales order does not require delivery.');
        }

        return Delivery::create([
            'sales_order_id'    => $order->id,
            'delivery_staff_id' => $deliveryStaffId,
            'delivery_date'     => $deliveryDate ?? $order->delivery_date ?? now()->toDateString(),
            'status'            => DeliveryStatus::Pending,
            'address'           => $address ?? $order->customer?->address,
            'notes'             => $notes,
        ]);
    }

    public function assignStaff(Delivery $delivery, User $staff): Delivery
    {
        $delivery->update(['delivery_staff_id' => $staff->id]);

        return $delivery->fresh();
    }

    public function updateStatus(Delivery $delivery, DeliveryStatus $status): Delivery
    {
        return DB::transaction(function () use ($delivery, $status) {
            $delivery->update(['status' => $status]);

            return $delivery->fresh();
        });
    }
}
