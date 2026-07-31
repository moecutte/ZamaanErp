<div class="h-screen flex flex-col" x-data>
    {{-- Top bar --}}
    <header class="h-14 shrink-0 bg-pos-panel border-b border-pos-line flex items-center justify-between px-4 gap-4">
        <div class="flex items-center gap-3 min-w-0">
            <div class="font-bold tracking-wide text-lg text-white">ZAMAAN POS</div>
            <div class="hidden sm:block text-xs text-gray-400 truncate">{{ auth()->user()?->name }}</div>
        </div>

        <div class="flex items-center gap-2 flex-1 max-w-xl">
            <input
                type="search"
                wire:model.live.debounce.250ms="search"
                placeholder="Search products…"
                class="w-full rounded-md bg-pos-bg border border-pos-line px-3 py-2 text-sm text-white placeholder:text-gray-500 focus:outline-none focus:ring-2 focus:ring-pos-accent"
            />
        </div>

        <div class="flex items-center gap-2">
            <select
                wire:model.live="customerId"
                class="rounded-md bg-pos-bg border border-pos-line px-3 py-2 text-sm text-white focus:outline-none focus:ring-2 focus:ring-pos-accent max-w-[12rem]"
            >
                <option value="">Customer…</option>
                @foreach ($this->customers as $customer)
                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                @endforeach
            </select>
            <a
                href="{{ url('/admin') }}"
                class="rounded-md bg-pos-card hover:bg-pos-line px-3 py-2 text-sm font-medium text-gray-200"
            >
                Back to ERP
            </a>
        </div>
    </header>

    @if ($toast)
        <div
            class="shrink-0 px-4 py-2 text-sm font-medium {{ $toastType === 'success' ? 'bg-emerald-700' : 'bg-rose-700' }}"
            wire:key="toast-{{ md5($toast) }}"
            x-data="{ show: true }"
            x-show="show"
            x-init="setTimeout(() => show = false, 4000)"
        >
            {{ $toast }}
        </div>
    @endif

    {{-- Main --}}
    <div class="flex-1 min-h-0 grid grid-cols-1 lg:grid-cols-12">
        {{-- Products --}}
        <section class="lg:col-span-8 min-h-0 overflow-y-auto pos-scroll p-3 bg-pos-bg">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-2">
                @forelse ($this->products as $product)
                    <button
                        type="button"
                        wire:click="addToCart({{ $product->id }})"
                        @disabled(! $product->in_stock || ! $customerId || $product->price === null)
                        class="text-left rounded-lg bg-pos-card hover:bg-pos-line disabled:opacity-35 disabled:hover:bg-pos-card disabled:cursor-not-allowed p-3 min-h-[7.5rem] flex flex-col justify-between border border-transparent hover:border-pos-accent transition"
                    >
                        <div>
                            <div class="font-semibold text-sm leading-snug line-clamp-2">{{ $product->name }}</div>
                            <div class="text-[11px] text-gray-400 mt-1">{{ $product->sku }}</div>
                        </div>
                        <div class="flex items-end justify-between gap-1 mt-2">
                            <div>
                                <div class="text-base font-bold text-white">
                                    @if ($product->price !== null)
                                        ${{ number_format($product->price, 2) }}
                                    @else
                                        —
                                    @endif
                                </div>
                                <div class="text-[10px] text-gray-400">{{ $product->unit }}</div>
                            </div>
                            <span class="text-[10px] px-1.5 py-0.5 rounded {{ $product->in_stock ? 'bg-emerald-900/60 text-emerald-300' : 'bg-rose-900/60 text-rose-300' }}">
                                {{ $product->in_stock ? number_format($product->available, 1) : 'Out' }}
                            </span>
                        </div>
                    </button>
                @empty
                    <div class="col-span-full text-center text-gray-500 py-20">No products found.</div>
                @endforelse
            </div>
        </section>

        {{-- Cart --}}
        <aside class="lg:col-span-4 min-h-0 flex flex-col border-l border-pos-line bg-pos-panel">
            <div class="px-4 py-3 border-b border-pos-line flex items-center justify-between">
                <div>
                    <div class="font-semibold">Order</div>
                    <div class="text-xs text-gray-400">{{ count($cart) }} lines · {{ $this->cartCount }} units</div>
                </div>
                @if (count($cart))
                    <button type="button" wire:click="clearCart" wire:confirm="Clear cart?" class="text-xs text-rose-300 hover:text-rose-200">
                        Clear
                    </button>
                @endif
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto pos-scroll divide-y divide-pos-line">
                @forelse ($cart as $key => $line)
                    <div class="px-4 py-3" wire:key="cart-{{ $key }}">
                        <div class="flex justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-sm truncate">{{ $line['name'] }}</div>
                                <div class="text-xs text-gray-400">${{ number_format($line['unit_price'], 2) }}</div>
                            </div>
                            <button type="button" wire:click="removeLine('{{ $key }}')" class="text-xs text-gray-400 hover:text-rose-300">✕</button>
                        </div>
                        <div class="mt-2 flex items-center justify-between">
                            <div class="inline-flex items-center rounded-md overflow-hidden border border-pos-line">
                                <button type="button" wire:click="decrement('{{ $key }}')" class="px-3 py-1 bg-pos-card hover:bg-pos-line text-lg leading-none">−</button>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="0.001"
                                    value="{{ $line['quantity'] }}"
                                    wire:change="updateQty('{{ $key }}', $event.target.value)"
                                    class="w-16 bg-pos-bg text-center text-sm border-0 focus:ring-0 py-1"
                                />
                                <button type="button" wire:click="increment('{{ $key }}')" class="px-3 py-1 bg-pos-card hover:bg-pos-line text-lg leading-none">+</button>
                            </div>
                            <div class="font-semibold">${{ number_format($line['subtotal'], 2) }}</div>
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-16 text-center text-gray-500 text-sm">
                        Tap products to build the order.
                    </div>
                @endforelse
            </div>

            <div class="shrink-0 border-t border-pos-line p-4 space-y-3 bg-pos-panel">
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Payment</label>
                    <select wire:model="paymentMethod" class="w-full rounded-md bg-pos-bg border border-pos-line px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-pos-accent">
                        @foreach ($this->paymentMethods as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center justify-between">
                    <span class="text-gray-400 text-sm">Total</span>
                    <span class="text-3xl font-bold">${{ number_format($this->cartTotal, 2) }}</span>
                </div>

                <button
                    type="button"
                    wire:click="checkout"
                    wire:loading.attr="disabled"
                    class="w-full rounded-md bg-pos-accent hover:bg-pos-accentHover disabled:opacity-60 py-3.5 text-base font-bold tracking-wide"
                >
                    <span wire:loading.remove wire:target="checkout">Payment</span>
                    <span wire:loading wire:target="checkout">Processing…</span>
                </button>
            </div>
        </aside>
    </div>
</div>
