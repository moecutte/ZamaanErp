<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Enums\StorageLocation;
use App\Enums\StockMovementType;
use App\Enums\SupplierType;
use App\Enums\UnitType;
use App\Enums\WastageReason;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $service;
    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReportService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $this->product = Product::create([
            'name' => 'Tuna',
            'sku' => 'TUN-001',
            'unit_type' => UnitType::WeightKg,
        ]);

        $customer = Customer::create([
            'name' => 'Buyer',
            'type' => CustomerType::Retailer,
        ]);

        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'channel' => SalesChannel::SalesOrder,
            'order_date' => now()->toDateString(),
            'status' => SalesOrderStatus::Invoiced,
            'created_by' => $this->user->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 20,
            'unit_price' => 8,
            'subtotal' => 160,
        ]);

        Batch::create([
            'product_id' => $this->product->id,
            'supplier_id' => $supplier->id,
            'expiry_date' => now()->addDays(2)->toDateString(),
            'quantity_received' => 50,
            'quantity_available' => 30,
            'storage_location' => StorageLocation::Frozen,
            'unit_cost' => 5,
        ]);

        // Seed stock movements for wastage %
        $batch = Batch::first();
        \App\Models\StockMovement::create([
            'batch_id' => $batch->id,
            'type' => StockMovementType::SaleOut,
            'quantity' => 20,
            'created_by' => $this->user->id,
        ]);
        \App\Models\StockMovement::create([
            'batch_id' => $batch->id,
            'type' => StockMovementType::WastageOut,
            'quantity' => 5,
            'reason' => 'Expired',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_sales_by_channel(): void
    {
        $rows = $this->service->salesByChannel();

        $this->assertTrue($rows->contains(fn ($r) => $r->channel === SalesChannel::SalesOrder->value));
        $this->assertEquals(160.0, (float) $rows->firstWhere('channel', SalesChannel::SalesOrder->value)->revenue);
    }

    public function test_top_products(): void
    {
        $rows = $this->service->topProducts();

        $this->assertEquals('Tuna', $rows->first()->name);
        $this->assertEquals(20.0, (float) $rows->first()->total_qty);
    }

    public function test_stock_aging_includes_near_expiry(): void
    {
        $rows = $this->service->stockAging();
        $near = $rows->firstWhere('bucket', '0–3 days');

        $this->assertNotNull($near);
        $this->assertGreaterThan(0, $near->batch_count);
    }

    public function test_wastage_percent(): void
    {
        $data = $this->service->wastagePercent();

        // 5 / (5+20) = 20%
        $this->assertEquals(20.0, $data->wastage_pct);
    }

    public function test_revenue_by_customer_type(): void
    {
        $rows = $this->service->revenueByCustomerType();

        $this->assertTrue($rows->contains(fn ($r) => $r->customer_type === CustomerType::Retailer->value));
    }

    public function test_dashboard_report_uses_product_not_fish_type(): void
    {
        $order = SalesOrder::first();
        \App\Models\Invoice::create([
            'sales_order_id' => $order->id,
            'invoice_number' => 'INV-TEST-001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'total_amount' => 160,
            'amount_paid' => 60,
            'status' => \App\Enums\InvoiceStatus::Partial,
        ]);

        $report = $this->service->dashboardReport();

        $this->assertEquals(160.0, $report['total_sales']);
        $this->assertEquals(100.0, $report['outstanding_debt']);
        $this->assertEquals(60.0, $report['total_collection']);
        $this->assertEquals(20.0, $report['total_kg_sold']);
        $this->assertEquals('Tuna', $report['product_kg']->first()->product);
        $this->assertTrue($report['form_kg']->isNotEmpty());
        $this->assertEquals($this->user->name, $report['salesperson_kg']->first()->salesperson);
    }
}
