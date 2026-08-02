<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\StorageLocation;
use App\Enums\SupplierType;
use App\Enums\UnitType;
use App\Livewire\PosTerminal;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerPriceOverride;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosTerminalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $household;
    private Product $product;
    private int $formId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'sales_staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->assignRole('sales_staff');
        $this->actingAs($this->user);

        $supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $this->product = Product::create([
            'name' => 'Tuna',
            'sku' => 'TUN-001',
            'unit_type' => UnitType::WeightKg,
            'category' => 'Fish',
        ]);
        $this->formId = $this->product->baseForm()->id;
        $this->household = Customer::create([
            'name' => 'Walk-in Household Customer',
            'type' => CustomerType::Household,
        ]);
        CustomerPriceOverride::create([
            'customer_id' => $this->household->id,
            'product_id' => $this->product->id,
            'price_per_unit' => 15,
        ]);
        Batch::create([
            'product_id' => $this->product->id,
            'supplier_id' => $supplier->id,
            'expiry_date' => now()->addDays(30)->toDateString(),
            'quantity_received' => 100,
            'quantity_available' => 100,
            'storage_location' => StorageLocation::Frozen,
            'unit_cost' => 5,
        ]);
    }

    public function test_checkout_ignores_tampered_cart_price(): void
    {
        $key = "{$this->product->id}:{$this->formId}";

        Livewire::test(PosTerminal::class)
            ->set('customerId', $this->household->id)
            ->set('cart', [
                $key => [
                    'product_id' => $this->product->id,
                    'product_form_id' => $this->formId,
                    'name' => $this->product->name . ' · Whole',
                    'sku' => $this->product->sku,
                    'form' => 'Whole',
                    'unit' => 'kg',
                    'quantity' => 2,
                    'unit_price' => 1.00, // tampered
                    'subtotal' => 2.00,
                ],
            ])
            ->set('paymentMethod', PaymentMethod::Cash->value)
            ->call('checkout')
            ->assertSet('toastType', 'success');

        $this->assertDatabaseHas('sales_order_lines', [
            'product_id' => $this->product->id,
            'product_form_id' => $this->formId,
            'unit_price' => 15,
            'quantity' => 2,
        ]);
    }

    public function test_checkout_rejects_non_household_customer(): void
    {
        $restaurant = Customer::create([
            'name' => 'Ocean Grill',
            'type' => CustomerType::Restaurant,
            'credit_limit' => 5000,
        ]);
        CustomerPriceOverride::create([
            'customer_id' => $restaurant->id,
            'product_id' => $this->product->id,
            'price_per_unit' => 12,
        ]);

        $key = "{$this->product->id}:{$this->formId}";

        Livewire::test(PosTerminal::class)
            ->set('customerId', $restaurant->id)
            ->set('cart', [
                $key => [
                    'product_id' => $this->product->id,
                    'product_form_id' => $this->formId,
                    'name' => $this->product->name . ' · Whole',
                    'sku' => $this->product->sku,
                    'form' => 'Whole',
                    'unit' => 'kg',
                    'quantity' => 1,
                    'unit_price' => 12,
                    'subtotal' => 12,
                ],
            ])
            ->call('checkout')
            ->assertSet('toastType', 'error');

        $this->assertDatabaseCount('sales_orders', 0);
    }
}
