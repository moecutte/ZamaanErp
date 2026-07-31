<?php

namespace Tests\Feature;

use App\Enums\StorageLocation;
use App\Enums\StockMovementType;
use App\Enums\SupplierType;
use App\Enums\UnitType;
use App\Enums\WastageReason;
use App\Models\Batch;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\WastageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WastageServiceTest extends TestCase
{
    use RefreshDatabase;

    private WastageService $service;
    private Batch $batch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WastageService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $product = Product::create(['name' => 'Tuna', 'sku' => 'TUN-001', 'unit_type' => UnitType::WeightKg]);

        $this->batch = Batch::create([
            'product_id'         => $product->id,
            'supplier_id'        => $supplier->id,
            'expiry_date'        => '2026-12-31',
            'quantity_received'  => 50,
            'quantity_available' => 50,
            'storage_location'   => StorageLocation::Frozen,
            'unit_cost'          => 5.00,
        ]);
    }

    public function test_wastage_decreases_stock_and_creates_movement(): void
    {
        $movement = $this->service->record($this->batch, 10, WastageReason::Expired);

        $this->assertEquals(40.0, (float) $this->batch->fresh()->quantity_available);
        $this->assertEquals(StockMovementType::WastageOut, $movement->type);
        $this->assertStringContainsString('Expired', $movement->reason);
    }

    public function test_wastage_reason_codes(): void
    {
        $this->service->record($this->batch, 1, WastageReason::Damaged, 'crushed box');
        $this->service->record($this->batch, 1, WastageReason::QualityReject, 'off smell');

        $this->assertDatabaseHas('stock_movements', [
            'type' => StockMovementType::WastageOut->value,
            'reason' => 'Damaged: crushed box',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'type' => StockMovementType::WastageOut->value,
            'reason' => 'Quality Reject: off smell',
        ]);
    }

    public function test_wastage_throws_on_insufficient_stock(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->record($this->batch, 999, WastageReason::Expired);
    }

    public function test_wastage_throws_on_zero_quantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->record($this->batch, 0, WastageReason::Expired);
    }
}
