<?php

namespace Tests\Feature;

use App\Enums\StorageLocation;
use App\Enums\StockMovementType;
use App\Enums\SupplierType;
use App\Enums\UnitType;
use App\Models\Batch;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $service;
    private Batch $batch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StockService::class);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $supplier = Supplier::create([
            'name' => 'Test Supplier',
            'type' => SupplierType::Company,
        ]);
        $product = Product::create([
            'name'      => 'Salmon',
            'sku'       => 'SAL-001',
            'unit_type' => UnitType::WeightKg,
        ]);
        $this->batch = Batch::create([
            'product_id'         => $product->id,
            'supplier_id'        => $supplier->id,
            'expiry_date'        => '2026-12-31',
            'quantity_received'  => 100,
            'quantity_available' => 50,
            'storage_location'   => StorageLocation::Frozen,
            'unit_cost'          => 8.00,
        ]);
    }

    public function test_record_in_increases_quantity_and_creates_movement(): void
    {
        $movement = $this->service->recordIn($this->batch, 20);

        $this->batch->refresh();

        $this->assertEquals(70, (float) $this->batch->quantity_available);
        $this->assertEquals(StockMovementType::PurchaseIn, $movement->type);
        $this->assertEquals(20, (float) $movement->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'batch_id' => $this->batch->id,
            'type'     => StockMovementType::PurchaseIn->value,
            'quantity' => 20,
        ]);
    }

    public function test_record_sale_out_decreases_quantity_and_creates_movement(): void
    {
        $movement = $this->service->recordSaleOut($this->batch, 10);

        $this->batch->refresh();

        $this->assertEquals(40, (float) $this->batch->quantity_available);
        $this->assertEquals(StockMovementType::SaleOut, $movement->type);
    }

    public function test_sale_out_throws_when_insufficient_stock(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->recordSaleOut($this->batch, 999);
    }

    public function test_record_wastage_decreases_quantity_and_creates_movement(): void
    {
        $movement = $this->service->recordWastage($this->batch, 5, 'expired');

        $this->batch->refresh();

        $this->assertEquals(45, (float) $this->batch->quantity_available);
        $this->assertEquals(StockMovementType::WastageOut, $movement->type);
        $this->assertEquals('expired', $movement->reason);
    }

    public function test_positive_adjustment_adds_stock(): void
    {
        $this->service->recordAdjustment($this->batch, 10, 'count correction');

        $this->batch->refresh();
        $this->assertEquals(60, (float) $this->batch->quantity_available);
    }

    public function test_negative_adjustment_removes_stock(): void
    {
        $this->service->recordAdjustment($this->batch, -10, 'count correction');

        $this->batch->refresh();
        $this->assertEquals(40, (float) $this->batch->quantity_available);
    }

    public function test_negative_adjustment_throws_when_insufficient(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->recordAdjustment($this->batch, -999, 'bad adjustment');
    }

    public function test_movement_records_creator(): void
    {
        $movement = $this->service->recordSaleOut($this->batch, 5);

        $this->assertEquals($this->user->id, $movement->created_by);
    }

    public function test_record_in_rejects_zero_or_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->recordIn($this->batch, 0);
    }
}
