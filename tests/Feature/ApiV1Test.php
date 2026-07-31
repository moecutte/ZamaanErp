<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use App\Enums\StorageLocation;
use App\Enums\SupplierType;
use App\Enums\UnitType;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerPriceOverride;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'warehouse_staff', 'sales_staff', 'delivery_staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create([
            'email' => 'api@zamaanerp.com',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('sales_staff');

        $supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $this->product = Product::create([
            'name' => 'Tuna',
            'sku' => 'TUN-001',
            'unit_type' => UnitType::WeightKg,
        ]);
        $this->customer = Customer::create([
            'name' => 'Walk-in',
            'type' => CustomerType::Household,
        ]);

        CustomerPriceOverride::create([
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'price_per_unit' => 15.00,
        ]);

        Batch::create([
            'product_id' => $this->product->id,
            'supplier_id' => $supplier->id,
            'expiry_date' => '2026-12-31',
            'quantity_received' => 100,
            'quantity_available' => 100,
            'storage_location' => StorageLocation::Frozen,
            'unit_cost' => 5,
        ]);
    }

    public function test_login_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'api@zamaanerp.com',
            'password' => 'password',
            'device_name' => 'flutter-pos',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'email']]);
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'api@zamaanerp.com',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_products_require_auth(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_list_products(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'TUN-001']);
    }

    public function test_stock_check(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/stock/' . $this->product->id)
            ->assertOk()
            ->assertJsonPath('data.available_quantity', 100)
            ->assertJsonStructure([
                'data' => [
                    'product',
                    'available_quantity',
                    'batches',
                ],
            ]);
    }

    public function test_create_sales_order_with_confirm(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $this->customer->id,
            'channel' => SalesChannel::Pos->value,
            'confirm' => true,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'invoiced')
            ->assertJsonPath('data.invoice.status', 'paid');

        $this->assertEquals(95.0, (float) Batch::first()->quantity_available);
    }

    public function test_create_draft_sales_order(): void
    {
        Sanctum::actingAs($this->user);

        $restaurant = Customer::create([
            'name' => 'Grill',
            'type' => CustomerType::Restaurant,
            'payment_terms_days' => 15,
        ]);
        CustomerPriceOverride::create([
            'customer_id' => $restaurant->id,
            'product_id' => $this->product->id,
            'price_per_unit' => 12,
        ]);

        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $restaurant->id,
            'channel' => SalesChannel::SalesOrder->value,
            'confirm' => false,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_record_payment(): void
    {
        Sanctum::actingAs($this->user);

        $restaurant = Customer::create([
            'name' => 'Grill',
            'type' => CustomerType::Restaurant,
            'payment_terms_days' => 15,
        ]);
        CustomerPriceOverride::create([
            'customer_id' => $restaurant->id,
            'product_id' => $this->product->id,
            'price_per_unit' => 10,
        ]);

        $orderResponse = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $restaurant->id,
            'channel' => SalesChannel::SalesOrder->value,
            'confirm' => true,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 10],
            ],
        ])->assertCreated();

        $invoiceId = $orderResponse->json('data.invoice.id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 40,
            'payment_method' => PaymentMethod::Zaad->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 40)
            ->assertJsonPath('data.invoice.status', 'partial');
    }

    public function test_list_customers(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Walk-in']);
    }

    public function test_ignores_client_unit_price(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $this->customer->id,
            'channel' => SalesChannel::Pos->value,
            'confirm' => false,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 1.00, // should be ignored / rejected by validation
                ],
            ],
        ]);

        // unit_price is no longer accepted — still creates with resolved price
        // If validation strips unknown keys, order is created at override price 15
        $response->assertCreated();
        $this->assertEquals(15.0, (float) $response->json('data.lines.0.unit_price'));
    }
}
