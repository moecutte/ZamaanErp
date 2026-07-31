<?php

namespace App\Livewire;

use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\ConfirmSalesOrderService;
use App\Services\PricingResolutionService;
use App\Services\StockAllocationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class PosTerminal extends Component
{
    public ?int $customerId = null;

    public string $search = '';

    public string $paymentMethod = 'cash';

    /** @var array<string, array{product_id: int, name: string, sku: string, unit: string, quantity: float, unit_price: float, subtotal: float}> */
    public array $cart = [];

    public ?string $toast = null;

    public string $toastType = 'success';

    public function mount(): void
    {
        abort_unless(
            Auth::user()?->hasAnyRole(['admin', 'sales_staff']),
            403
        );

        $walkIn = Customer::query()
            ->where('type', CustomerType::Household)
            ->orderBy('id')
            ->first();

        $this->customerId = $walkIn?->id;
        $this->paymentMethod = PaymentMethod::Cash->value;
    }

    public function getCustomersProperty(): Collection
    {
        return Customer::query()
            ->where('type', CustomerType::Household)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function getPaymentMethodsProperty(): array
    {
        return collect(PaymentMethod::cases())
            ->mapWithKeys(fn (PaymentMethod $m) => [$m->value => $m->label()])
            ->all();
    }

    public function getProductsProperty(): Collection
    {
        $allocator = app(StockAllocationService::class);
        $pricing = app(PricingResolutionService::class);
        $customer = $this->customerId ? Customer::find($this->customerId) : null;

        $query = Product::query()->orderBy('name');

        if (trim($this->search) !== '') {
            $term = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('species', 'like', $term);
            });
        }

        return $query->limit(80)->get()->map(function (Product $product) use ($allocator, $pricing, $customer) {
            $available = $allocator->availableQuantity($product);
            $price = $customer
                ? $pricing->resolve($customer, $product, 1)
                : null;

            return (object) [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit_type?->label() ?? '',
                'available' => $available,
                'price' => $price,
                'in_stock' => $available > 0,
            ];
        });
    }

    public function getCartTotalProperty(): float
    {
        return round(collect($this->cart)->sum('subtotal'), 2);
    }

    public function getCartCountProperty(): float
    {
        return round(collect($this->cart)->sum('quantity'), 3);
    }

    public function updatedCustomerId(): void
    {
        $this->repriceCart();
    }

    public function addToCart(int $productId): void
    {
        $this->toast = null;
        $product = Product::find($productId);
        if (! $product) {
            return;
        }

        $available = app(StockAllocationService::class)->availableQuantity($product);
        if ($available <= 0) {
            $this->flash('Out of stock', 'error');

            return;
        }

        $key = (string) $productId;

        if (isset($this->cart[$key])) {
            $newQty = round((float) $this->cart[$key]['quantity'] + 1, 3);
            if ($newQty > $available) {
                $this->flash("Only {$available} available.", 'error');

                return;
            }
            $this->cart[$key]['quantity'] = $newQty;
            $this->cart[$key]['subtotal'] = round($newQty * (float) $this->cart[$key]['unit_price'], 2);

            return;
        }

        $customer = Customer::find($this->customerId);
        $price = $customer
            ? app(PricingResolutionService::class)->resolve($customer, $product, 1)
            : null;

        if ($price === null) {
            $this->flash("No price configured for {$product->name}.", 'error');

            return;
        }

        $this->cart[$key] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit' => $product->unit_type?->label() ?? '',
            'quantity' => 1.0,
            'unit_price' => $price,
            'subtotal' => $price,
        ];
    }

    public function increment(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $product = Product::find($this->cart[$key]['product_id']);
        $available = $product
            ? app(StockAllocationService::class)->availableQuantity($product)
            : 0;

        $newQty = round((float) $this->cart[$key]['quantity'] + 1, 3);
        if ($newQty > $available) {
            $this->flash("Only {$available} available.", 'error');

            return;
        }

        $this->setQty($key, $newQty);
    }

    public function decrement(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $newQty = round((float) $this->cart[$key]['quantity'] - 1, 3);
        if ($newQty <= 0) {
            unset($this->cart[$key]);

            return;
        }

        $this->setQty($key, $newQty);
    }

    public function updateQty(string $key, $quantity): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $qty = (float) $quantity;
        if ($qty <= 0) {
            unset($this->cart[$key]);

            return;
        }

        $product = Product::find($this->cart[$key]['product_id']);
        $available = $product
            ? app(StockAllocationService::class)->availableQuantity($product)
            : 0;

        if ($qty > $available) {
            $this->flash("Only {$available} available.", 'error');
            $qty = $available;
        }

        $this->setQty($key, $qty);
    }

    public function removeLine(string $key): void
    {
        unset($this->cart[$key]);
    }

    public function clearCart(): void
    {
        $this->cart = [];
    }

    public function checkout(): void
    {
        $this->toast = null;

        if (! $this->customerId) {
            $this->flash('Select a customer', 'error');

            return;
        }

        if ($this->cart === []) {
            $this->flash('Cart is empty', 'error');

            return;
        }

        try {
            $method = PaymentMethod::from($this->paymentMethod);

            $order = DB::transaction(function () use ($method) {
                $order = SalesOrder::create([
                    'customer_id' => $this->customerId,
                    'channel' => SalesChannel::Pos,
                    'order_date' => now()->toDateString(),
                    'status' => SalesOrderStatus::Draft,
                    'delivery_required' => false,
                    'created_by' => Auth::id(),
                ]);

                foreach ($this->cart as $line) {
                    $qty = (float) $line['quantity'];
                    $price = (float) $line['unit_price'];

                    $order->lines()->create([
                        'product_id' => $line['product_id'],
                        'batch_id' => null,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'subtotal' => round($qty * $price, 2),
                    ]);
                }

                return app(ConfirmSalesOrderService::class)->confirm($order, $method);
            });

            $total = number_format((float) $order->invoice?->total_amount, 2);
            $this->cart = [];
            $this->flash("Sale #{$order->id} · \${$total} · {$method->label()}", 'success');
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    public function render()
    {
        return view('livewire.pos-terminal')
            ->layout('layouts.pos', [
                'title' => 'POS — Zamaan ERP',
            ]);
    }

    private function flash(string $message, string $type): void
    {
        $this->toast = $message;
        $this->toastType = $type;
    }

    private function setQty(string $key, float $qty): void
    {
        $customer = Customer::find($this->customerId);
        $product = Product::find($this->cart[$key]['product_id']);

        $price = (float) $this->cart[$key]['unit_price'];
        if ($customer && $product) {
            $resolved = app(PricingResolutionService::class)->resolve($customer, $product, $qty);
            if ($resolved !== null) {
                $price = $resolved;
            }
        }

        $this->cart[$key]['quantity'] = $qty;
        $this->cart[$key]['unit_price'] = $price;
        $this->cart[$key]['subtotal'] = round($qty * $price, 2);
    }

    private function repriceCart(): void
    {
        foreach (array_keys($this->cart) as $key) {
            $this->setQty($key, (float) $this->cart[$key]['quantity']);
        }
    }
}
