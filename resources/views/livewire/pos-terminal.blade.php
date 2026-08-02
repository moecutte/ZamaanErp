<div class="pos-shell">
    <header class="pos-header">
        <div class="pos-brand">
            <img src="{{ asset('images/zamaan-logo-dark.png') }}" alt="Zamaan Seafood" class="pos-brand-logo">
            <span>{{ auth()->user()?->name }}</span>
        </div>

        <input
            type="search"
            class="pos-search"
            wire:model.live.debounce.250ms="search"
            placeholder="Search products by name or SKU…"
        />

        <div class="pos-header-actions">
            <select class="pos-select" wire:model.live="customerId">
                <option value="">Customer…</option>
                @foreach ($this->customers as $customer)
                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                @endforeach
            </select>
            <a class="pos-btn pos-btn-link" href="{{ url('/admin') }}">Back to ERP</a>
        </div>
    </header>

    @if ($toast)
        <div
            class="pos-toast {{ $toastType === 'success' ? 'pos-toast-success' : 'pos-toast-error' }}"
            wire:key="toast-{{ md5($toast) }}"
            x-data="{ show: true }"
            x-show="show"
            x-init="setTimeout(() => show = false, 4000)"
            x-cloak
        >
            {{ $toast }}
        </div>
    @endif

    <div class="pos-body">
        <section class="pos-catalog">
            <div class="pos-categories">
                <button
                    type="button"
                    class="pos-chip {{ $category === 'all' ? 'is-active' : '' }}"
                    wire:click="setCategory('all')"
                >All</button>
                @foreach ($this->categories as $cat)
                    <button
                        type="button"
                        class="pos-chip {{ $category === $cat ? 'is-active' : '' }}"
                        wire:click="setCategory(@js($cat))"
                    >{{ $cat }}</button>
                @endforeach
            </div>

            <div class="pos-grid-wrap">
                <div class="pos-grid">
                    @forelse ($this->products as $product)
                        <button
                            type="button"
                            class="pos-product"
                            wire:click="addToCart({{ $product->id }}, {{ $product->form_id }})"
                            @disabled(! $product->in_stock || ! $customerId || $product->price === null)
                        >
                            <div>
                                <div class="pos-product-name">{{ $product->name }}</div>
                                <div class="pos-product-sku">{{ $product->sku }} · {{ $product->form }}</div>
                            </div>
                            <div class="pos-product-footer">
                                <div>
                                    <div class="pos-product-price">
                                        @if ($product->price !== null)
                                            {{ \App\Support\Money::format($product->price) }}
                                        @else
                                            —
                                        @endif
                                    </div>
                                    <div class="pos-product-unit">{{ $product->unit }}</div>
                                </div>
                                <span class="pos-badge {{ $product->in_stock ? 'pos-badge-ok' : 'pos-badge-bad' }}">
                                    {{ $product->in_stock ? number_format($product->available, 1) . ' left' : 'Out' }}
                                </span>
                            </div>
                        </button>
                    @empty
                        <div class="pos-empty">No products match your search.</div>
                    @endforelse
                </div>
            </div>
        </section>

        <aside class="pos-cart">
            <div class="pos-cart-head">
                <div>
                    <h2>Current Order</h2>
                    <p>{{ count($cart) }} line(s) · {{ $this->cartCount }} units</p>
                </div>
                @if (count($cart))
                    <button type="button" class="pos-cart-clear" wire:click="clearCart" wire:confirm="Clear the cart?">
                        Clear
                    </button>
                @endif
            </div>

            <div class="pos-cart-lines">
                @forelse ($cart as $key => $line)
                    <div class="pos-line" wire:key="cart-{{ $key }}">
                        <div class="pos-line-top">
                            <div style="min-width:0">
                                <div class="pos-line-name">{{ $line['name'] }}</div>
                                <div class="pos-line-meta">{{ \App\Support\Money::format($line['unit_price']) }} / {{ $line['unit'] }}@if (!empty($line['form'])) · {{ $line['form'] }}@endif</div>
                            </div>
                            <button type="button" class="pos-line-remove" wire:click="removeLine('{{ $key }}')" title="Remove">✕</button>
                        </div>
                        <div class="pos-line-bottom">
                            <div class="pos-qty">
                                <button type="button" wire:click="decrement('{{ $key }}')">−</button>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="0.001"
                                    value="{{ $line['quantity'] }}"
                                    wire:change="updateQty('{{ $key }}', $event.target.value)"
                                />
                                <button type="button" wire:click="increment('{{ $key }}')">+</button>
                            </div>
                            <div class="pos-line-subtotal">{{ \App\Support\Money::format($line['subtotal']) }}</div>
                        </div>
                    </div>
                @empty
                    <div class="pos-cart-empty">Tap products on the left to add them here.</div>
                @endforelse
            </div>

            <div class="pos-cart-footer">
                <label for="pos-payment">Payment method</label>
                <select id="pos-payment" class="pos-select" wire:model="paymentMethod">
                    @foreach ($this->paymentMethods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>

                <div class="pos-total-row">
                    <span>Total</span>
                    <strong>{{ \App\Support\Money::format($this->cartTotal) }}</strong>
                </div>

                <button
                    type="button"
                    class="pos-pay-btn"
                    wire:click="checkout"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="checkout">Payment</span>
                    <span wire:loading wire:target="checkout">Processing…</span>
                </button>
            </div>
        </aside>
    </div>
</div>
