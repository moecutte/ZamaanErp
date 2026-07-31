<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Enums\StorageLocation;
use App\Enums\SupplierType;
use App\Enums\UnitType;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ConfirmSalesOrderService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $service;
    private User $user;
    private Customer $individual;
    private Customer $restaurant;
    private Product $product;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InvoiceService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->supplier = Supplier::create(['name' => 'Fish Co', 'type' => SupplierType::Company]);
        $this->product = Product::create(['name' => 'Tuna', 'sku' => 'TUN-001', 'unit_type' => UnitType::WeightKg]);

        $this->individual = Customer::create([
            'name' => 'Walk-in',
            'type' => CustomerType::Household,
            'payment_terms_days' => 0,
        ]);

        $this->restaurant = Customer::create([
            'name' => 'Ocean Grill',
            'type' => CustomerType::Restaurant,
            'credit_limit' => 5000,
            'payment_terms_days' => 30,
        ]);

        Batch::create([
            'product_id'         => $this->product->id,
            'supplier_id'        => $this->supplier->id,
            'expiry_date'        => '2026-12-31',
            'quantity_received'  => 100,
            'quantity_available' => 100,
            'storage_location'   => StorageLocation::Frozen,
            'unit_cost'          => 5,
        ]);
    }

    private function confirmOrder(Customer $customer, SalesChannel $channel, float $qty = 10, float $price = 12): SalesOrder
    {
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
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

        return app(ConfirmSalesOrderService::class)->confirm($order);
    }

    public function test_retail_invoice_is_paid_immediately_with_cash(): void
    {
        $order = $this->confirmOrder($this->individual, SalesChannel::Pos, 5, 10);

        $invoice = $order->invoice;
        $this->assertNotNull($invoice);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->status);
        $this->assertEquals(50.0, (float) $invoice->total_amount);
        $this->assertEquals(50.0, (float) $invoice->amount_paid);
        $this->assertDatabaseHas('payments', [
            'invoice_id'     => $invoice->id,
            'amount'         => 50,
            'payment_method' => PaymentMethod::Cash->value,
        ]);
    }

    public function test_restaurant_invoice_is_unpaid_with_due_date(): void
    {
        $order = $this->confirmOrder($this->restaurant, SalesChannel::SalesOrder, 10, 12);

        $invoice = $order->invoice;
        $this->assertEquals(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertEquals(120.0, (float) $invoice->total_amount);
        $this->assertEquals(0.0, (float) $invoice->amount_paid);
        $this->assertEquals(
            now()->addDays(30)->toDateString(),
            $invoice->due_date->toDateString()
        );
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_partial_payment_updates_status(): void
    {
        $order = $this->confirmOrder($this->restaurant, SalesChannel::SalesOrder, 10, 10);
        $invoice = $order->invoice;

        $this->service->applyPayment($invoice, 40, PaymentMethod::Zaad);

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::Partial, $invoice->status);
        $this->assertEquals(40.0, (float) $invoice->amount_paid);
    }

    public function test_full_payment_marks_paid(): void
    {
        $order = $this->confirmOrder($this->restaurant, SalesChannel::SalesOrder, 10, 10);
        $invoice = $order->invoice;

        $this->service->applyPayment($invoice, 50, PaymentMethod::Edahab);
        $this->service->applyPayment($invoice->fresh(), 50, PaymentMethod::BankTransfer);

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::Paid, $invoice->status);
        $this->assertEquals(100.0, (float) $invoice->amount_paid);
    }

    public function test_overpayment_throws(): void
    {
        $order = $this->confirmOrder($this->restaurant, SalesChannel::SalesOrder, 5, 10);
        $invoice = $order->invoice;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds outstanding/');

        $this->service->applyPayment($invoice, 999, PaymentMethod::Cash);
    }

    public function test_overdue_status_when_past_due_and_unpaid(): void
    {
        $order = $this->confirmOrder($this->restaurant, SalesChannel::SalesOrder, 5, 10);
        $invoice = $order->invoice;
        $invoice->update(['due_date' => now()->subDays(5)->toDateString()]);

        $status = $this->service->refreshStatus($invoice->fresh());

        $this->assertEquals(InvoiceStatus::Overdue, $status);
    }

    public function test_invoice_number_is_unique_and_formatted(): void
    {
        $order = $this->confirmOrder($this->individual, SalesChannel::Pos, 1, 10);

        $this->assertMatchesRegularExpression(
            '/^INV-\d{8}-\d{4}$/',
            $order->invoice->invoice_number
        );
    }

    public function test_generate_is_idempotent(): void
    {
        $order = $this->confirmOrder($this->restaurant, SalesChannel::SalesOrder, 5, 10);
        $first = $order->invoice;

        $second = $this->service->generateForOrder($order->fresh());

        $this->assertEquals($first->id, $second->id);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_mark_overdue_invoices(): void
    {
        $order = $this->confirmOrder($this->restaurant, SalesChannel::SalesOrder, 5, 10);
        $invoice = $order->invoice;
        $invoice->update(['due_date' => now()->subDays(3)->toDateString()]);

        $this->assertEquals(InvoiceStatus::Unpaid, $invoice->status);

        $count = $this->service->markOverdueInvoices();

        $this->assertEquals(1, $count);
        $this->assertEquals(InvoiceStatus::Overdue, $invoice->fresh()->status);
    }
}
