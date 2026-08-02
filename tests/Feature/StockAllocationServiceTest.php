<?php

namespace Tests\Feature;

use App\Enums\StorageLocation;
use App\Enums\SupplierType;
use App\Models\Batch;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\StockAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockAllocationService $service;
    private Product $product;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service  = app(StockAllocationService::class);
        $this->supplier = Supplier::create([
            'name' => 'Test Supplier',
            'type' => SupplierType::Company,
        ]);
        $this->product = Product::create([
            'name'      => 'Tuna',
            'sku'       => 'TUN-001',
            'unit_type' => \App\Enums\UnitType::WeightKg,
        ]);
    }

    private function makeBatch(float $qty, string $expiryDate): Batch
    {
        return Batch::create([
            'product_id'         => $this->product->id,
            'supplier_id'        => $this->supplier->id,
            'expiry_date'        => $expiryDate,
            'quantity_received'  => $qty,
            'quantity_available' => $qty,
            'storage_location'   => StorageLocation::Frozen,
            'unit_cost'          => 5.00,
        ]);
    }

    public function test_fefo_allocates_nearest_expiry_first(): void
    {
        $batchFarExpiry  = $this->makeBatch(10, '2026-12-31');
        $batchNearExpiry = $this->makeBatch(10, '2026-08-01');

        $allocations = $this->service->allocate($this->product, 5);

        $this->assertCount(1, $allocations);
        $this->assertEquals($batchNearExpiry->id, $allocations->first()->batch->id);
        $this->assertEquals(5.0, $allocations->first()->quantity_to_deduct);
    }

    public function test_fefo_spans_multiple_batches_when_needed(): void
    {
        $batch1 = $this->makeBatch(5, '2026-08-01');
        $batch2 = $this->makeBatch(5, '2026-09-01');

        $allocations = $this->service->allocate($this->product, 8);

        $this->assertCount(2, $allocations);
        $this->assertEquals($batch1->id, $allocations->first()->batch->id);
        $this->assertEquals(5.0, $allocations->first()->quantity_to_deduct);
        $this->assertEquals($batch2->id, $allocations->last()->batch->id);
        $this->assertEquals(3.0, $allocations->last()->quantity_to_deduct);
    }

    public function test_exact_full_depletion_across_all_batches(): void
    {
        $this->makeBatch(10, '2026-08-01');
        $this->makeBatch(10, '2026-09-01');

        $allocations = $this->service->allocate($this->product, 20);

        $totalDeducted = $allocations->sum('quantity_to_deduct');
        $this->assertEquals(20.0, $totalDeducted);
    }

    public function test_throws_when_insufficient_stock(): void
    {
        $this->makeBatch(5, '2026-08-01');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $this->service->allocate($this->product, 10);
    }

    public function test_throws_when_no_batches_exist(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->allocate($this->product, 1);
    }

    public function test_skips_zero_quantity_batches(): void
    {
        $this->makeBatch(0, '2026-07-01'); // depleted, nearest expiry
        $activeBatch = $this->makeBatch(10, '2026-12-31');

        $allocations = $this->service->allocate($this->product, 5);

        $this->assertCount(1, $allocations);
        $this->assertEquals($activeBatch->id, $allocations->first()->batch->id);
    }

    public function test_available_quantity_sums_all_batches(): void
    {
        $this->makeBatch(10, '2026-08-01');
        $this->makeBatch(15, '2026-09-01');

        $available = $this->service->availableQuantity($this->product);

        $this->assertEquals(25.0, $available);
    }

    public function test_allocates_only_requested_form(): void
    {
        $fillet = \App\Models\ProductForm::create([
            'product_id' => $this->product->id,
            'name' => 'Fillet',
            'code' => 'fillet',
            'is_base' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $wholeBatch = $this->makeBatch(20, '2026-08-01');
        $filletBatch = Batch::create([
            'product_id' => $this->product->id,
            'product_form_id' => $fillet->id,
            'supplier_id' => $this->supplier->id,
            'expiry_date' => '2026-08-01',
            'quantity_received' => 5,
            'quantity_available' => 5,
            'storage_location' => StorageLocation::Frozen,
            'unit_cost' => 5.00,
        ]);

        $allocations = $this->service->allocate($this->product, 3, $fillet);

        $this->assertCount(1, $allocations);
        $this->assertEquals($filletBatch->id, $allocations->first()->batch->id);
        $this->assertEquals(20.0, $this->service->availableQuantity($this->product));
        $this->assertEquals(5.0, $this->service->availableQuantity($this->product, $fillet));
        $this->assertEquals($wholeBatch->id, $this->service->allocate($this->product, 1)->first()->batch->id);
    }

    public function test_skips_expired_batches(): void
    {
        $this->makeBatch(50, now()->subDay()->toDateString());
        $active = $this->makeBatch(10, now()->addDays(10)->toDateString());

        $allocations = $this->service->allocate($this->product, 5);

        $this->assertCount(1, $allocations);
        $this->assertEquals($active->id, $allocations->first()->batch->id);
        $this->assertEquals(10.0, $this->service->availableQuantity($this->product));
    }

    public function test_throws_when_only_expired_stock_exists(): void
    {
        $this->makeBatch(50, now()->subDays(5)->toDateString());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $this->service->allocate($this->product, 1);
    }
}
