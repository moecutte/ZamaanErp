<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\DeliveryStatus;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Enums\StorageLocation;
use App\Enums\SupplierType;
use App\Enums\UnitType;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ConfirmSalesOrderService;
use App\Services\DeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryService $service;
    private User $user;
    private User $driver;
    private Customer $customer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DeliveryService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        Role::create(['name' => 'delivery_staff']);
        $this->driver = User::factory()->create(['name' => 'Driver One']);
        $this->driver->assignRole('delivery_staff');

        $supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $this->product = Product::create(['name' => 'Tuna', 'sku' => 'TUN-001', 'unit_type' => UnitType::WeightKg]);
        $this->customer = Customer::create([
            'name'    => 'Ocean Grill',
            'type'    => CustomerType::Restaurant,
            'address' => '123 Harbor St',
            'payment_terms_days' => 15,
        ]);

        Batch::create([
            'product_id'         => $this->product->id,
            'supplier_id'        => $supplier->id,
            'expiry_date'        => '2026-12-31',
            'quantity_received'  => 100,
            'quantity_available' => 100,
            'storage_location'   => StorageLocation::Frozen,
            'unit_cost'          => 5,
        ]);
    }

    private function confirmWithDelivery(): SalesOrder
    {
        $order = SalesOrder::create([
            'customer_id'       => $this->customer->id,
            'channel'           => SalesChannel::SalesOrder,
            'order_date'        => now()->toDateString(),
            'status'            => SalesOrderStatus::Draft,
            'delivery_required' => true,
            'delivery_date'     => now()->addDay()->toDateString(),
            'created_by'        => $this->user->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id'     => $this->product->id,
            'quantity'       => 5,
            'unit_price'     => 10,
            'subtotal'       => 50,
        ]);

        return app(ConfirmSalesOrderService::class)->confirm($order);
    }

    public function test_confirm_auto_creates_pending_delivery(): void
    {
        $order = $this->confirmWithDelivery();

        $this->assertNotNull($order->delivery);
        $this->assertEquals(DeliveryStatus::Pending, $order->delivery->status);
        $this->assertEquals('123 Harbor St', $order->delivery->address);
    }

    public function test_cannot_create_delivery_when_not_required(): void
    {
        $order = SalesOrder::create([
            'customer_id'       => $this->customer->id,
            'channel'           => SalesChannel::SalesOrder,
            'order_date'        => now()->toDateString(),
            'status'            => SalesOrderStatus::Confirmed,
            'delivery_required' => false,
            'created_by'        => $this->user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not require delivery/');

        $this->service->createForOrder($order);
    }

    public function test_assign_staff(): void
    {
        $order = $this->confirmWithDelivery();
        $delivery = $this->service->assignStaff($order->delivery, $this->driver);

        $this->assertEquals($this->driver->id, $delivery->delivery_staff_id);
    }

    public function test_status_transitions(): void
    {
        $order = $this->confirmWithDelivery();
        $delivery = $order->delivery;

        $this->service->updateStatus($delivery, DeliveryStatus::InTransit);
        $this->assertEquals(DeliveryStatus::InTransit, $delivery->fresh()->status);

        $this->service->updateStatus($delivery->fresh(), DeliveryStatus::Delivered);
        $this->assertEquals(DeliveryStatus::Delivered, $delivery->fresh()->status);
    }

    public function test_create_is_idempotent(): void
    {
        $order = $this->confirmWithDelivery();
        $first = $order->delivery;
        $second = $this->service->createForOrder($order->fresh());

        $this->assertEquals($first->id, $second->id);
        $this->assertDatabaseCount('deliveries', 1);
    }
}
