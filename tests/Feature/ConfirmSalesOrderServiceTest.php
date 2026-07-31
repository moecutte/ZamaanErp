<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Enums\StorageLocation;
use App\Enums\StockMovementType;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmSalesOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConfirmSalesOrderService $service;
    private User $user;
    private Customer $customer;
    private Product $product;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ConfirmSalesOrderService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $this->product = Product::create(['name' => 'Tuna', 'sku' => 'TUN-001', 'unit_type' => UnitType::WeightKg]);
        $this->customer = Customer::create([
            'name' => 'Walk-in',
            'type' => CustomerType::Household,
        ]);
    }

    private function makeBatch(float $qty, string $expiry): Batch
    {
        return Batch::create([
            'product_id'         => $this->product->id,
            'supplier_id'        => $this->supplier->id,
            'expiry_date'        => $expiry,
            'quantity_received'  => $qty,
            'quantity_available' => $qty,
            'storage_location'   => StorageLocation::Frozen,
            'unit_cost'          => 5.00,
        ]);
    }

    private function makeDraftOrder(SalesChannel $channel, float $qty, float $price = 10.00): SalesOrder
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'channel'     => $channel,
            'order_date'  => now()->toDateString(),
            'status'      => SalesOrderStatus::Draft,
            'created_by'  => $this->user->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id'     => $this->product->id,
            'quantity'       => $qty,
            'unit_price'     => $price,
            'subtotal'       => $qty * $price,
        ]);

        return $order;
    }

    public function test_retail_confirm_sets_status_invoiced(): void
    {
        $this->makeBatch(20, '2026-12-31');
        $order = $this->makeDraftOrder(SalesChannel::Pos, 5);

        $result = $this->service->confirm($order);

        $this->assertEquals(SalesOrderStatus::Invoiced, $result->status);
        $this->assertNotNull($result->invoice);
        $this->assertEquals(\App\Enums\InvoiceStatus::Paid, $result->invoice->status);
    }

    public function test_restaurant_confirm_sets_status_invoiced_unpaid(): void
    {
        $this->makeBatch(20, '2026-12-31');
        $order = $this->makeDraftOrder(SalesChannel::SalesOrder, 5);

        $result = $this->service->confirm($order);

        $this->assertEquals(SalesOrderStatus::Invoiced, $result->status);
        $this->assertEquals(\App\Enums\InvoiceStatus::Unpaid, $result->invoice->status);
    }

    public function test_wholesale_confirm_sets_status_invoiced_unpaid(): void
    {
        $this->makeBatch(20, '2026-12-31');
        $order = $this->makeDraftOrder(SalesChannel::SalesOrder, 5);

        $result = $this->service->confirm($order);

        $this->assertEquals(SalesOrderStatus::Invoiced, $result->status);
        $this->assertEquals(\App\Enums\InvoiceStatus::Unpaid, $result->invoice->status);
    }

    public function test_fefo_allocates_nearest_expiry_batch(): void
    {
        $near = $this->makeBatch(10, '2026-08-01');
        $far = $this->makeBatch(10, '2026-12-31');
        $order = $this->makeDraftOrder(SalesChannel::Pos, 5);

        $this->service->confirm($order);

        $line = $order->fresh()->lines->first();
        $this->assertEquals($near->id, $line->batch_id);
        $this->assertEquals(5.0, (float) $near->fresh()->quantity_available);
        $this->assertEquals(10.0, (float) $far->fresh()->quantity_available);
    }

    public function test_splits_line_across_multiple_batches(): void
    {
        $batch1 = $this->makeBatch(5, '2026-08-01');
        $batch2 = $this->makeBatch(5, '2026-09-01');
        $order = $this->makeDraftOrder(SalesChannel::SalesOrder, 8);

        $this->service->confirm($order);

        $lines = $order->fresh()->lines;
        $this->assertCount(2, $lines);
        $this->assertEquals(5.0, (float) $lines->firstWhere('batch_id', $batch1->id)->quantity);
        $this->assertEquals(3.0, (float) $lines->firstWhere('batch_id', $batch2->id)->quantity);
    }

    public function test_creates_sale_out_stock_movements(): void
    {
        $this->makeBatch(20, '2026-12-31');
        $order = $this->makeDraftOrder(SalesChannel::Pos, 7);

        $this->service->confirm($order);

        $this->assertDatabaseHas('stock_movements', [
            'type'           => StockMovementType::SaleOut->value,
            'quantity'       => 7,
            'reference_type' => SalesOrder::class,
            'reference_id'   => $order->id,
        ]);
    }

    public function test_throws_on_insufficient_stock(): void
    {
        $this->makeBatch(3, '2026-12-31');
        $order = $this->makeDraftOrder(SalesChannel::Pos, 10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $this->service->confirm($order);
    }

    public function test_throws_when_not_draft(): void
    {
        $this->makeBatch(20, '2026-12-31');
        $order = $this->makeDraftOrder(SalesChannel::Pos, 5);
        $order->update(['status' => SalesOrderStatus::Confirmed]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Only draft orders/');

        $this->service->confirm($order);
    }

    public function test_throws_when_no_lines(): void
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'channel'     => SalesChannel::Pos,
            'order_date'  => now()->toDateString(),
            'status'      => SalesOrderStatus::Draft,
            'created_by'  => $this->user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no lines/');

        $this->service->confirm($order);
    }

    public function test_rolls_back_on_failure_leaving_draft_intact(): void
    {
        $this->makeBatch(3, '2026-12-31');
        $order = $this->makeDraftOrder(SalesChannel::Pos, 10);

        try {
            $this->service->confirm($order);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertEquals(SalesOrderStatus::Draft, $order->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertEquals(3.0, (float) Batch::first()->quantity_available);
    }

    public function test_credit_limit_blocks_confirm(): void
    {
        $this->makeBatch(100, now()->addDays(60)->toDateString());

        $customer = Customer::create([
            'name' => 'Limited Grill',
            'type' => CustomerType::Restaurant,
            'credit_limit' => 50,
            'payment_terms_days' => 15,
        ]);

        $order = SalesOrder::create([
            'customer_id' => $customer->id,
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Credit limit/');

        $this->service->confirm($order);
    }
}
