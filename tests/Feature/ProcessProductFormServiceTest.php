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
use App\Models\ProductForm;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CancelSalesOrderService;
use App\Services\ConfirmSalesOrderService;
use App\Services\ProcessProductFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessProductFormServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProcessProductFormService $service;
    private User $user;
    private Product $product;
    private ProductForm $whole;
    private ProductForm $fillet;
    private ProductForm $steak;
    private Supplier $supplier;
    private Batch $sourceBatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProcessProductFormService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $this->product = Product::create([
            'name' => 'Yellowfin Tuna',
            'sku' => 'TUN-YF-001',
            'unit_type' => UnitType::WeightKg,
        ]);

        $this->whole = $this->product->baseForm();
        $this->fillet = ProductForm::create([
            'product_id' => $this->product->id,
            'name' => 'Fillet',
            'code' => 'fillet',
            'is_base' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $this->steak = ProductForm::create([
            'product_id' => $this->product->id,
            'name' => 'Steak',
            'code' => 'steak',
            'is_base' => false,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->sourceBatch = Batch::create([
            'product_id' => $this->product->id,
            'product_form_id' => $this->whole->id,
            'supplier_id' => $this->supplier->id,
            'expiry_date' => now()->addDays(20)->toDateString(),
            'quantity_received' => 100,
            'quantity_available' => 100,
            'storage_location' => StorageLocation::Chilled,
            'unit_cost' => 8.50,
        ]);
    }

    public function test_processing_weighs_and_moves_stock(): void
    {
        $processing = $this->service->process(
            sourceBatch: $this->sourceBatch,
            sourceQuantity: 10,
            wasteQuantity: 2.5,
            outputs: [
                ['product_form_id' => $this->fillet->id, 'quantity' => 5],
                ['product_form_id' => $this->steak->id, 'quantity' => 2.5],
            ],
            notes: 'Morning cut',
        );

        $this->assertEquals(10, (float) $processing->source_quantity);
        $this->assertEquals(2.5, (float) $processing->waste_quantity);
        $this->assertCount(2, $processing->outputs);

        $this->sourceBatch->refresh();
        $this->assertEquals(90.0, (float) $this->sourceBatch->quantity_available);

        $filletBatch = Batch::query()
            ->where('product_form_id', $this->fillet->id)
            ->first();
        $steakBatch = Batch::query()
            ->where('product_form_id', $this->steak->id)
            ->first();

        $this->assertNotNull($filletBatch);
        $this->assertNotNull($steakBatch);
        $this->assertEquals(5.0, (float) $filletBatch->quantity_available);
        $this->assertEquals(2.5, (float) $steakBatch->quantity_available);

        $this->assertDatabaseHas('stock_movements', [
            'batch_id' => $this->sourceBatch->id,
            'type' => StockMovementType::ProcessingOut->value,
            'quantity' => 7.5,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'batch_id' => $this->sourceBatch->id,
            'type' => StockMovementType::WastageOut->value,
            'quantity' => 2.5,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'batch_id' => $filletBatch->id,
            'type' => StockMovementType::ProcessingIn->value,
            'quantity' => 5,
        ]);
    }

    public function test_processing_rejects_unbalanced_weights(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/do not balance/');

        $this->service->process(
            sourceBatch: $this->sourceBatch,
            sourceQuantity: 10,
            wasteQuantity: 1,
            outputs: [
                ['product_form_id' => $this->fillet->id, 'quantity' => 5],
            ],
        );
    }

    public function test_processing_throws_on_insufficient_stock(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->process(
            sourceBatch: $this->sourceBatch,
            sourceQuantity: 200,
            wasteQuantity: 50,
            outputs: [
                ['product_form_id' => $this->fillet->id, 'quantity' => 150],
            ],
        );
    }

    public function test_sale_allocates_only_selected_form_stock(): void
    {
        $this->service->process(
            sourceBatch: $this->sourceBatch,
            sourceQuantity: 10,
            wasteQuantity: 2,
            outputs: [
                ['product_form_id' => $this->fillet->id, 'quantity' => 8],
            ],
        );

        $customer = Customer::create([
            'name' => 'Walk-in',
            'type' => CustomerType::Household,
        ]);

        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'channel' => SalesChannel::Pos,
            'order_date' => now()->toDateString(),
            'status' => SalesOrderStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_form_id' => $this->fillet->id,
            'quantity' => 3,
            'unit_price' => 20,
            'subtotal' => 60,
        ]);

        $confirmed = app(ConfirmSalesOrderService::class)->confirm($order);
        $line = $confirmed->lines->first();

        $this->assertEquals($this->fillet->id, $line->product_form_id);
        $this->assertEquals($this->fillet->id, $line->batch->product_form_id);
        $this->assertEquals(5.0, (float) Batch::query()->where('product_form_id', $this->fillet->id)->sum('quantity_available'));
        // Whole stock untouched by the fillet sale beyond processing
        $this->assertEquals(90.0, (float) $this->sourceBatch->fresh()->quantity_available);
    }

    public function test_cancel_restores_form_batch_stock(): void
    {
        $this->service->process(
            sourceBatch: $this->sourceBatch,
            sourceQuantity: 10,
            wasteQuantity: 2,
            outputs: [
                ['product_form_id' => $this->fillet->id, 'quantity' => 8],
            ],
        );

        $customer = Customer::create([
            'name' => 'Walk-in',
            'type' => CustomerType::Household,
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
            'product_form_id' => $this->fillet->id,
            'quantity' => 4,
            'unit_price' => 20,
            'subtotal' => 80,
        ]);

        $confirmed = app(ConfirmSalesOrderService::class)->confirm($order);
        $batchId = $confirmed->lines->first()->batch_id;

        app(CancelSalesOrderService::class)->cancel($confirmed);

        $this->assertEquals(8.0, (float) Batch::find($batchId)->quantity_available);
    }

    public function test_cannot_process_into_base_form(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/base form/');

        $this->service->process(
            sourceBatch: $this->sourceBatch,
            sourceQuantity: 5,
            wasteQuantity: 0,
            outputs: [
                ['product_form_id' => $this->whole->id, 'quantity' => 5],
            ],
        );
    }
}
