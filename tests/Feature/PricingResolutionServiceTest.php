<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\UnitType;
use App\Models\Customer;
use App\Models\CustomerPriceOverride;
use App\Models\PriceListItem;
use App\Models\PricingTier;
use App\Models\Product;
use App\Services\PricingResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PricingResolutionService $service;
    private Product $product;
    private PricingTier $wholesaleTier;
    private PricingTier $restaurantTier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PricingResolutionService::class);

        $this->product = Product::create([
            'name'      => 'Tuna',
            'sku'       => 'TUN-001',
            'unit_type' => UnitType::WeightKg,
        ]);

        $this->wholesaleTier = PricingTier::create([
            'name'          => 'Wholesale Standard',
            'customer_type' => CustomerType::Retailer,
        ]);

        $this->restaurantTier = PricingTier::create([
            'name'          => 'Restaurant Standard',
            'customer_type' => CustomerType::Restaurant,
        ]);

        // Wholesale: base $10, break at qty 50 → $8, break at qty 100 → $6
        PriceListItem::create([
            'pricing_tier_id' => $this->wholesaleTier->id,
            'product_id'      => $this->product->id,
            'price_per_unit'  => 10.00,
            'min_quantity'    => 0,
        ]);
        PriceListItem::create([
            'pricing_tier_id' => $this->wholesaleTier->id,
            'product_id'      => $this->product->id,
            'price_per_unit'  => 8.00,
            'min_quantity'    => 50,
        ]);
        PriceListItem::create([
            'pricing_tier_id' => $this->wholesaleTier->id,
            'product_id'      => $this->product->id,
            'price_per_unit'  => 6.00,
            'min_quantity'    => 100,
        ]);

        // Restaurant: flat $12
        PriceListItem::create([
            'pricing_tier_id' => $this->restaurantTier->id,
            'product_id'      => $this->product->id,
            'price_per_unit'  => 12.00,
            'min_quantity'    => 0,
        ]);
    }

    private function makeCustomer(CustomerType $type, ?int $tierId = null): Customer
    {
        return Customer::create([
            'name'            => 'Test Customer',
            'type'            => $type,
            'pricing_tier_id' => $tierId,
        ]);
    }

    public function test_returns_null_when_no_tier_and_no_override(): void
    {
        $customer = $this->makeCustomer(CustomerType::Household);

        $price = $this->service->resolve($customer, $this->product, 1);

        $this->assertNull($price);
    }

    public function test_returns_tier_base_price(): void
    {
        $customer = $this->makeCustomer(CustomerType::Retailer, $this->wholesaleTier->id);

        $price = $this->service->resolve($customer, $this->product, 10);

        $this->assertEquals(10.00, $price);
    }

    public function test_applies_first_quantity_break(): void
    {
        $customer = $this->makeCustomer(CustomerType::Retailer, $this->wholesaleTier->id);

        $price = $this->service->resolve($customer, $this->product, 50);

        $this->assertEquals(8.00, $price);
    }

    public function test_applies_highest_qualifying_quantity_break(): void
    {
        $customer = $this->makeCustomer(CustomerType::Retailer, $this->wholesaleTier->id);

        $price = $this->service->resolve($customer, $this->product, 150);

        $this->assertEquals(6.00, $price);
    }

    public function test_quantity_just_below_break_uses_lower_tier(): void
    {
        $customer = $this->makeCustomer(CustomerType::Retailer, $this->wholesaleTier->id);

        $price = $this->service->resolve($customer, $this->product, 49.999);

        $this->assertEquals(10.00, $price);
    }

    public function test_customer_override_takes_priority_over_tier(): void
    {
        $customer = $this->makeCustomer(CustomerType::Retailer, $this->wholesaleTier->id);

        CustomerPriceOverride::create([
            'customer_id'    => $customer->id,
            'product_id'     => $this->product->id,
            'price_per_unit' => 4.50,
        ]);

        // Even at qty 150 (which would be $6 via tier), override wins
        $price = $this->service->resolve($customer, $this->product, 150);

        $this->assertEquals(4.50, $price);
    }

    public function test_override_works_without_pricing_tier(): void
    {
        $customer = $this->makeCustomer(CustomerType::Restaurant);

        CustomerPriceOverride::create([
            'customer_id'    => $customer->id,
            'product_id'     => $this->product->id,
            'price_per_unit' => 9.00,
        ]);

        $price = $this->service->resolve($customer, $this->product, 1);

        $this->assertEquals(9.00, $price);
    }

    public function test_restaurant_tier_price(): void
    {
        $customer = $this->makeCustomer(CustomerType::Restaurant, $this->restaurantTier->id);

        $price = $this->service->resolve($customer, $this->product, 5);

        $this->assertEquals(12.00, $price);
    }

    public function test_resolve_or_fail_throws_when_no_price(): void
    {
        $customer = $this->makeCustomer(CustomerType::Household);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No price configured/');

        $this->service->resolveOrFail($customer, $this->product, 1);
    }

    public function test_resolve_or_fail_returns_price_when_found(): void
    {
        $customer = $this->makeCustomer(CustomerType::Restaurant, $this->restaurantTier->id);

        $price = $this->service->resolveOrFail($customer, $this->product, 1);

        $this->assertEquals(12.00, $price);
    }

    public function test_returns_null_for_product_not_on_tier(): void
    {
        $otherProduct = Product::create([
            'name'      => 'Salmon',
            'sku'       => 'SAL-001',
            'unit_type' => UnitType::WeightKg,
        ]);

        $customer = $this->makeCustomer(CustomerType::Retailer, $this->wholesaleTier->id);

        $price = $this->service->resolve($customer, $otherProduct, 10);

        $this->assertNull($price);
    }
}
