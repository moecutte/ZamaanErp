<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StorageLocation;
use App\Enums\StockMovementType;
use App\Enums\SupplierType;
use App\Enums\UnitType;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReceivePurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivePurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceivePurchaseOrderService $service;
    private User $user;
    private Supplier $supplier;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service  = app(ReceivePurchaseOrderService::class);
        $this->user     = User::factory()->create();
        $this->supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $this->productA = Product::create(['name' => 'Tuna', 'sku' => 'TUN-001', 'unit_type' => UnitType::WeightKg]);
        $this->productB = Product::create(['name' => 'Salmon', 'sku' => 'SAL-001', 'unit_type' => UnitType::WeightKg]);
    }

    private function makePO(array $lines): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'order_date'  => now()->toDateString(),
            'status'      => PurchaseOrderStatus::Pending,
            'total_cost'  => collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_cost']),
        ]);

        foreach ($lines as $line) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po->id,
                'product_id'        => $line['product_id'],
                'quantity'          => $line['quantity'],
                'unit_cost'         => $line['unit_cost'],
            ]);
        }

        return $po;
    }

    private function lineDetails(PurchaseOrder $po, string $expiry, string $location = 'frozen'): array
    {
        return $po->lines->mapWithKeys(fn ($line) => [
            $line->id => [
                'expiry_date'      => $expiry,
                'catch_date'       => null,
                'production_date'  => null,
                'storage_location' => $location,
            ],
        ])->toArray();
    }

    public function test_receive_creates_a_batch_per_line(): void
    {
        $po = $this->makePO([
            ['product_id' => $this->productA->id, 'quantity' => 20, 'unit_cost' => 5],
            ['product_id' => $this->productB->id, 'quantity' => 10, 'unit_cost' => 8],
        ]);

        $this->service->receive($po, $this->lineDetails($po, '2026-12-31'), $this->user->id);

        $this->assertDatabaseCount('batches', 2);
    }

    public function test_batch_quantity_matches_po_line(): void
    {
        $po = $this->makePO([
            ['product_id' => $this->productA->id, 'quantity' => 30, 'unit_cost' => 5],
        ]);

        $this->service->receive($po, $this->lineDetails($po, '2026-12-31'), $this->user->id);

        $this->assertDatabaseHas('batches', [
            'product_id'         => $this->productA->id,
            'quantity_received'  => 30,
            'quantity_available' => 30,
        ]);
    }

    public function test_stock_movement_created_per_line(): void
    {
        $po = $this->makePO([
            ['product_id' => $this->productA->id, 'quantity' => 20, 'unit_cost' => 5],
            ['product_id' => $this->productB->id, 'quantity' => 10, 'unit_cost' => 8],
        ]);

        $this->service->receive($po, $this->lineDetails($po, '2026-12-31'), $this->user->id);

        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', [
            'type'       => StockMovementType::PurchaseIn->value,
            'quantity'   => 20,
            'created_by' => $this->user->id,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'type'     => StockMovementType::PurchaseIn->value,
            'quantity' => 10,
        ]);
    }

    public function test_po_status_set_to_received(): void
    {
        $po = $this->makePO([
            ['product_id' => $this->productA->id, 'quantity' => 5, 'unit_cost' => 5],
        ]);

        $this->service->receive($po, $this->lineDetails($po, '2026-12-31'), $this->user->id);

        $this->assertEquals(PurchaseOrderStatus::Received, $po->fresh()->status);
    }

    public function test_batch_linked_to_po_line(): void
    {
        $po = $this->makePO([
            ['product_id' => $this->productA->id, 'quantity' => 15, 'unit_cost' => 5],
        ]);

        $this->service->receive($po, $this->lineDetails($po, '2026-12-31'), $this->user->id);

        $line = $po->lines()->first()->fresh();
        $this->assertNotNull($line->batch_id);
    }

    public function test_batch_expiry_date_and_storage_location_stored(): void
    {
        $po = $this->makePO([
            ['product_id' => $this->productA->id, 'quantity' => 10, 'unit_cost' => 5],
        ]);

        $details = $po->lines->mapWithKeys(fn ($line) => [
            $line->id => [
                'expiry_date'      => '2026-09-30',
                'catch_date'       => '2026-07-01',
                'production_date'  => '2026-07-02',
                'storage_location' => StorageLocation::Chilled->value,
            ],
        ])->toArray();

        $this->service->receive($po, $details, $this->user->id);

        $batch = \App\Models\Batch::first();
        $this->assertEquals('2026-09-30', $batch->expiry_date->toDateString());
        $this->assertEquals('2026-07-01', $batch->catch_date->toDateString());
        $this->assertEquals('2026-07-02', $batch->production_date->toDateString());
        $this->assertEquals(StorageLocation::Chilled, $batch->storage_location);
    }

    public function test_receiving_already_received_po_throws(): void
    {
        $po = $this->makePO([
            ['product_id' => $this->productA->id, 'quantity' => 5, 'unit_cost' => 5],
        ]);

        // Receive once
        $this->service->receive($po, $this->lineDetails($po, '2026-12-31'), $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been received/');

        // Attempt to receive again
        $this->service->receive($po->fresh(), $this->lineDetails($po, '2026-12-31'), $this->user->id);
    }

    public function test_movement_references_the_purchase_order(): void
    {
        $po = $this->makePO([
            ['product_id' => $this->productA->id, 'quantity' => 10, 'unit_cost' => 5],
        ]);

        $this->service->receive($po, $this->lineDetails($po, '2026-12-31'), $this->user->id);

        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => PurchaseOrder::class,
            'reference_id'   => $po->id,
        ]);
    }

    public function test_batch_code_auto_generated(): void
    {
        $po = $this->makePO([
            ['product_id' => $this->productA->id, 'quantity' => 10, 'unit_cost' => 5],
        ]);

        $this->service->receive($po, $this->lineDetails($po, '2026-12-31'), $this->user->id);

        $batch = \App\Models\Batch::first();
        $this->assertMatchesRegularExpression('/^BCH-\d{8}-[A-Z0-9]{8}$/', $batch->batch_code);
    }
}
