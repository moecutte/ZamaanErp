<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\InvoiceStatus;
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
use App\Services\CancelSalesOrderService;
use App\Services\ConfirmSalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelSalesOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private CancelSalesOrderService $cancel;
    private ConfirmSalesOrderService $confirm;
    private User $user;
    private Customer $customer;
    private Product $product;
    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cancel = app(CancelSalesOrderService::class);
        $this->confirm = app(ConfirmSalesOrderService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $this->product = Product::create(['name' => 'Tuna', 'sku' => 'TUN-001', 'unit_type' => UnitType::WeightKg]);
        $this->customer = Customer::create([
            'name' => 'Walk-in',
            'type' => CustomerType::Household,
        ]);
        $this->batch = Batch::create([
            'product_id' => $this->product->id,
            'supplier_id' => $supplier->id,
            'expiry_date' => now()->addDays(30)->toDateString(),
            'quantity_received' => 100,
            'quantity_available' => 100,
            'storage_location' => StorageLocation::Frozen,
            'unit_cost' => 5,
        ]);
    }

    public function test_cancel_draft_does_not_touch_stock(): void
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'channel' => SalesChannel::Pos,
            'order_date' => now()->toDateString(),
            'status' => SalesOrderStatus::Draft,
            'created_by' => $this->user->id,
        ]);
        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 10,
            'subtotal' => 50,
        ]);

        $this->cancel->cancel($order);

        $this->assertEquals(SalesOrderStatus::Cancelled, $order->fresh()->status);
        $this->assertEquals(100.0, (float) $this->batch->fresh()->quantity_available);
    }

    public function test_cancel_confirmed_restores_stock(): void
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'channel' => SalesChannel::SalesOrder,
            'order_date' => now()->toDateString(),
            'status' => SalesOrderStatus::Draft,
            'created_by' => $this->user->id,
        ]);
        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_price' => 12,
            'subtotal' => 120,
        ]);

        $restaurant = Customer::create([
            'name' => 'Grill',
            'type' => CustomerType::Restaurant,
            'payment_terms_days' => 15,
            'credit_limit' => 5000,
        ]);
        $order->update(['customer_id' => $restaurant->id, 'channel' => SalesChannel::SalesOrder]);

        $confirmed = $this->confirm->confirm($order->fresh());
        $this->assertEquals(90.0, (float) $this->batch->fresh()->quantity_available);
        $this->assertEquals(0.0, (float) $confirmed->invoice->amount_paid);

        $this->cancel->cancel($confirmed);

        $this->assertEquals(SalesOrderStatus::Cancelled, $confirmed->fresh()->status);
        $this->assertEquals(100.0, (float) $this->batch->fresh()->quantity_available);
        $this->assertEquals(InvoiceStatus::Cancelled, $confirmed->fresh()->invoice->status);
    }

    public function test_cannot_pay_cancelled_invoice(): void
    {
        $restaurant = Customer::create([
            'name' => 'Grill',
            'type' => CustomerType::Restaurant,
            'payment_terms_days' => 15,
            'credit_limit' => 5000,
        ]);

        $order = SalesOrder::create([
            'customer_id' => $restaurant->id,
            'channel' => SalesChannel::SalesOrder,
            'order_date' => now()->toDateString(),
            'status' => SalesOrderStatus::Draft,
            'created_by' => $this->user->id,
        ]);
        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 10,
            'subtotal' => 50,
        ]);

        $confirmed = $this->confirm->confirm($order);
        $this->cancel->cancel($confirmed);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cancelled invoice/');

        app(\App\Services\InvoiceService::class)->applyPayment(
            $confirmed->fresh()->invoice,
            10,
            \App\Enums\PaymentMethod::Cash,
        );
    }

    public function test_cancel_marks_delivery_cancelled(): void
    {
        $restaurant = Customer::create([
            'name' => 'Grill',
            'type' => CustomerType::Restaurant,
            'payment_terms_days' => 15,
            'credit_limit' => 5000,
        ]);

        $order = SalesOrder::create([
            'customer_id' => $restaurant->id,
            'channel' => SalesChannel::SalesOrder,
            'order_date' => now()->toDateString(),
            'status' => SalesOrderStatus::Draft,
            'delivery_required' => true,
            'delivery_date' => now()->addDay()->toDateString(),
            'created_by' => $this->user->id,
        ]);
        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 10,
            'subtotal' => 50,
        ]);

        $confirmed = $this->confirm->confirm($order);
        $this->assertNotNull($confirmed->delivery);

        $this->cancel->cancel($confirmed);

        $this->assertEquals(
            \App\Enums\DeliveryStatus::Cancelled,
            $confirmed->fresh()->delivery->status
        );
    }

    public function test_cannot_cancel_paid_order(): void
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'channel' => SalesChannel::Pos,
            'order_date' => now()->toDateString(),
            'status' => SalesOrderStatus::Draft,
            'created_by' => $this->user->id,
        ]);
        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 10,
            'subtotal' => 50,
        ]);

        $confirmed = $this->confirm->confirm($order);
        $this->assertEquals(InvoiceStatus::Paid, $confirmed->invoice->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/payments/');

        $this->cancel->cancel($confirmed);
    }
}
