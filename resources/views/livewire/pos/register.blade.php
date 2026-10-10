<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Support\PaymentMethod;
use App\Support\PosCalculator;
use App\Support\PosCheckout;
use App\Support\ReceiptProof;
use App\Support\Tenant;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithFileUploads;

    /** The scan / quick-add box — a barcode scanner types into this and presses Enter. */
    public string $scan = '';

    public string $search = '';

    public string $category = 'all';

    /** A one-line message under the scan box ("sold already", "no exact match"…). */
    public string $notice = '';

    public bool $noticeIsError = false;

    /**
     * One row per serialized unit, or one row per non-serialized product with
     * a quantity. Only ids and the cashier's choices live here — prices come
     * from the catalog on every render, so there is nothing to tamper with.
     *
     * @var array<int, array{product_id: int, product_serial_id: ?int, quantity: int, discount_type: string, discount_value: string}>
     */
    public array $cart = [];

    /** A serialized product whose units are being chosen from. */
    public ?int $pickingProductId = null;

    public ?int $customer_id = null;

    public string $walkInName = '';

    public string $walkInPhone = '';

    public string $billDiscountType = PosCalculator::FIXED;

    public string $billDiscountValue = '';

    public string $notes = '';

    /** @var array<int, array{method: string, bank_id: int|string|null, amount: string, reference: string, tendered: string}> */
    public array $payments = [];

    /** Bank slip per payment line, keyed like $payments. */
    public array $proofs = [];

    public function mount(): void
    {
        abort_unless(Tenant::current()?->posEnabled(), 404);

        $this->payments = [$this->blankPayment()];
    }

    /** @return array{method: string, bank_id: null, amount: string, reference: string, tendered: string} */
    private function blankPayment(): array
    {
        return ['method' => PaymentMethod::CASH, 'bank_id' => null, 'amount' => '', 'reference' => '', 'tendered' => ''];
    }

    // ── Catalog ─────────────────────────────────────────────────────────

    #[Computed]
    public function products()
    {
        return Product::query()
            ->where('is_active', true)
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('sku', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
                ->orWhere('model', 'like', "%{$this->search}%")))
            ->when($this->category !== 'all', fn ($q) => $q->where('category', $this->category))
            ->withAvailableQuantity()
            ->with('media')
            ->orderBy('name')
            ->limit(48)
            ->get();
    }

    /** In-stock units of the product being picked, minus any already in the cart. */
    #[Computed]
    public function pickerSerials()
    {
        if (! $this->pickingProductId) {
            return collect();
        }

        return ProductSerial::where('product_id', $this->pickingProductId)
            ->where('status', 'in_stock')
            ->whereNotIn('id', $this->serialIdsInCart() ?: [0])
            ->orderBy('serial_number')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function pickingProduct(): ?Product
    {
        return $this->pickingProductId ? Product::find($this->pickingProductId) : null;
    }

    #[Computed]
    public function banks()
    {
        return PaymentMethod::banks();
    }

    /** @return array<int, string> */
    private function serialIdsInCart(): array
    {
        return collect($this->cart)->pluck('product_serial_id')->filter()->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function newLine(int $productId, ?int $serialId = null): array
    {
        return [
            'product_id' => $productId,
            'product_serial_id' => $serialId,
            'quantity' => 1,
            'discount_type' => PosCalculator::FIXED,
            'discount_value' => '',
        ];
    }

    private function say(string $message, bool $error = false): void
    {
        $this->notice = $message;
        $this->noticeIsError = $error;
    }

    // ── Customer ────────────────────────────────────────────────────────

    /** @return array<int, array{id: int, label: string, sublabel: string}> */
    public function searchCustomers(string $query): array
    {
        if (mb_strlen($query) < 2) {
            return [];
        }

        return Customer::where('is_active', true)
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$query}%")
                ->orWhere('last_name', 'like', "%{$query}%")
                ->orWhere('cnic_number', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%"))
            ->orderBy('first_name')
            ->limit(15)
            ->get()
            ->map(fn ($customer) => [
                'id' => $customer->id,
                'label' => $customer->full_name,
                'sublabel' => "{$customer->cnic_number} · {$customer->phone}",
            ])
            ->all();
    }

    // ── Building the cart ───────────────────────────────────────────────

    /**
     * Enter in the scan box: a unit's serial/IMEI, then a product SKU, and
     * otherwise whatever was typed becomes the search filter.
     */
    public function submitScan(): void
    {
        $code = trim($this->scan);
        $this->scan = '';
        $this->say('');

        if ($code === '') {
            return;
        }

        if ($serial = ProductSerial::where('serial_number', $code)->first()) {
            if ($serial->status !== 'in_stock') {
                $this->say("{$code} has already been sold.", true);

                return;
            }

            $this->addSerial($serial->id);

            return;
        }

        if ($product = Product::where('is_active', true)->where('sku', $code)->first()) {
            $this->addProduct($product->id);

            return;
        }

        $this->search = $code;
        $this->say("No exact match for \"{$code}\" — showing matching products.");
    }

    public function addProduct(int $productId): void
    {
        $this->say('');

        $product = Product::where('is_active', true)->withAvailableQuantity()->find($productId);

        if (! $product) {
            $this->say('That product is not available.', true);

            return;
        }

        if ($product->is_serialized) {
            $units = ProductSerial::where('product_id', $product->id)
                ->where('status', 'in_stock')
                ->whereNotIn('id', $this->serialIdsInCart() ?: [0])
                ->orderBy('id')
                ->pluck('id');

            if ($units->isEmpty()) {
                $this->say("No units of {$product->name} left to add.", true);

                return;
            }

            // One unit left needs no choosing; otherwise the cashier picks
            // the exact one (the IMEI on the box in front of them).
            if ($units->count() === 1) {
                $this->addSerial($units->first());

                return;
            }

            $this->pickingProductId = $product->id;
            unset($this->pickerSerials, $this->pickingProduct);

            return;
        }

        $inCart = (int) collect($this->cart)->where('product_id', $product->id)->sum('quantity');

        if ($inCart + 1 > $product->availableQuantity()) {
            $this->say($product->availableQuantity() > 0
                ? "Only {$product->availableQuantity()} of {$product->name} in stock."
                : "{$product->name} is out of stock.", true);

            return;
        }

        $existing = collect($this->cart)->search(
            fn ($line) => (int) $line['product_id'] === $product->id && empty($line['product_serial_id'])
        );

        if ($existing !== false) {
            $this->cart[$existing]['quantity'] = (int) $this->cart[$existing]['quantity'] + 1;
        } else {
            $this->cart[] = $this->newLine($product->id);
        }
    }

    public function addSerial(int $serialId): void
    {
        $this->say('');

        $serial = ProductSerial::where('status', 'in_stock')->find($serialId);
        $product = $serial ? Product::where('is_active', true)->find($serial->product_id) : null;

        if (! $serial || ! $product) {
            $this->say('That unit is no longer available.', true);
            $this->pickingProductId = null;

            return;
        }

        if (in_array($serial->id, $this->serialIdsInCart(), true)) {
            $this->say("{$serial->serial_number} is already on this sale.", true);

            return;
        }

        $this->cart[] = $this->newLine($product->id, $serial->id);
        $this->pickingProductId = null;
        unset($this->pickerSerials, $this->pickingProduct);
    }

    public function cancelPicking(): void
    {
        $this->pickingProductId = null;
        unset($this->pickerSerials, $this->pickingProduct);
    }

    public function removeLine(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
        $this->resetErrorBag();
    }

    public function changeQuantity(int $index, int $delta): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        $this->setQuantity($index, (int) $this->cart[$index]['quantity'] + $delta);
    }

    private function setQuantity(int $index, int $quantity): void
    {
        $line = $this->cart[$index];
        $product = Product::withAvailableQuantity()->find($line['product_id']);

        // A serialized unit is always exactly one.
        $max = $product && ! $product->is_serialized ? max(1, $product->availableQuantity()) : 1;

        $this->cart[$index]['quantity'] = max(1, min($max, $quantity));
    }

    /** Typing a quantity straight into the box is held to the same limits as the +/- buttons. */
    public function updatedCart(mixed $value, ?string $key = null): void
    {
        // Replacing the whole cart (no nested key) has nothing to clamp.
        if ($key === null) {
            return;
        }

        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        if ($field === 'quantity' && isset($this->cart[(int) $index])) {
            $this->setQuantity((int) $index, (int) $value);
        }
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->pickingProductId = null;
        $this->billDiscountValue = '';
        $this->resetErrorBag();
        $this->say('');
    }

    // ── Totals ──────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function lines(): array
    {
        $products = Product::whereIn('id', collect($this->cart)->pluck('product_id')->all())
            ->withAvailableQuantity()
            ->get()
            ->keyBy('id');

        $serials = ProductSerial::whereIn('id', $this->serialIdsInCart() ?: [0])->get()->keyBy('id');

        $lines = [];

        foreach ($this->cart as $index => $line) {
            $product = $products->get((int) $line['product_id']);

            if (! $product) {
                continue;
            }

            $lines[$index] = [
                'product' => $product,
                'serial' => $serials->get((int) ($line['product_serial_id'] ?? 0)),
                'unit_price' => (string) $product->cash_price,
                'quantity' => (int) $line['quantity'],
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
            ];
        }

        return $lines;
    }

    #[Computed]
    public function totals(): array
    {
        return PosCalculator::totals(
            collect($this->lines)
                ->map(fn (array $line) => Arr::only($line, ['unit_price', 'quantity', 'discount_type', 'discount_value']))
                ->all(),
            $this->billDiscountType,
            $this->billDiscountValue,
        );
    }

    /**
     * The payment lines as they will be charged: a line left blank takes
     * whatever is still unpaid, so the usual single-cash sale is one click.
     *
     * @return array<int, array<string, mixed>>
     */
    public function effectivePayments(): array
    {
        $entered = '0.00';

        foreach ($this->payments as $payment) {
            $entered = bcadd($entered, PosCalculator::number($payment['amount'] ?? ''), 2);
        }

        $remaining = bcsub($this->totals['total'], $entered, 2);
        $filled = false;
        $effective = [];

        foreach ($this->payments as $index => $payment) {
            if (trim((string) ($payment['amount'] ?? '')) === '') {
                if (! $filled && bccomp($remaining, '0', 2) > 0) {
                    $payment['amount'] = $remaining;
                    $filled = true;
                } else {
                    $payment['amount'] = '0';
                }
            }

            $effective[$index] = $payment;
        }

        return $effective;
    }

    // ── Payments ────────────────────────────────────────────────────────

    public function addPayment(): void
    {
        $this->payments[] = $this->blankPayment();
    }

    public function removePayment(int $index): void
    {
        if (count($this->payments) <= 1) {
            return;
        }

        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);

        $proofs = [];

        foreach ($this->proofs as $key => $file) {
            if ($key < $index) {
                $proofs[$key] = $file;
            } elseif ($key > $index) {
                $proofs[$key - 1] = $file;
            }
        }

        $this->proofs = $proofs;
    }

    public function setPaymentMethod(int $index, string $method): void
    {
        if (! isset($this->payments[$index]) || ! in_array($method, PaymentMethod::methods(), true)) {
            return;
        }

        if ($method === PaymentMethod::BANK && $this->banks->isEmpty()) {
            return;
        }

        $this->payments[$index]['method'] = $method;
        $this->payments[$index]['bank_id'] = null;
        $this->payments[$index]['reference'] = '';
        $this->payments[$index]['tendered'] = '';
        unset($this->proofs[$index]);
    }

    // ── Checkout ────────────────────────────────────────────────────────

    public function checkout(): void
    {
        $this->resetErrorBag();

        $this->validate([
            'proofs.*' => ReceiptProof::validationRule(),
            'walkInName' => 'nullable|string|max:255',
            'walkInPhone' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:1000',
        ]);

        $payments = collect($this->effectivePayments())
            ->map(fn (array $payment, int $index) => [
                'method' => $payment['method'],
                'bank_id' => $payment['bank_id'] ?: null,
                'amount' => (string) $payment['amount'],
                'reference' => $payment['reference'],
                'tendered' => $payment['tendered'],
                'proof' => $this->proofs[$index] ?? null,
            ])
            ->values()
            ->all();

        $sale = (new PosCheckout(Tenant::current(), auth()->user()))->complete(
            lines: array_values($this->cart),
            payments: $payments,
            details: [
                'customer_id' => $this->customer_id,
                'walk_in_name' => $this->walkInName,
                'walk_in_phone' => $this->walkInPhone,
                'bill_discount_type' => $this->billDiscountType,
                'bill_discount_value' => $this->billDiscountValue,
                'notes' => $this->notes,
            ],
        );

        session()->flash('status', "Sale {$sale->sale_number} completed.");

        $this->redirect(route('tenant.sales.show', $sale), navigate: true);
    }
} ?>

@slot('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold" style="color: var(--theme-bg-text)">Point of Sale</h1>
            <p class="text-sm opacity-70" style="color: var(--theme-bg-text)">Sell products outright — paid now, no agreement</p>
        </div>
        <a href="{{ route('tenant.sales.index') }}" wire:navigate
           class="inline-flex items-center gap-2 rounded-lg bg-white/70 border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white">
            <x-tenant-icon name="receipt" class="h-4 w-4" /> Sales
        </a>
    </div>
@endslot

@php
    $money = fn ($value) => \App\Support\PosCalculator::money($value);
    $card = 'rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5';
    $totals = $this->totals;
    $effective = $this->effectivePayments();
    $cartCount = collect($this->cart)->sum('quantity');
@endphp

<div class="grid grid-cols-1 xl:grid-cols-5 gap-6">
    {{-- Catalog ─────────────────────────────────────────────────────── --}}
    <div class="xl:col-span-3 space-y-4">
        <div class="{{ $card }} p-4 space-y-3">
            <div>
                <label for="pos-scan" class="sr-only">Scan or type a SKU / serial number</label>
                <div class="relative">
                    <x-tenant-icon name="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                    <input id="pos-scan" type="text" wire:model="scan" wire:keydown.enter.prevent="submitScan"
                        x-init="$el.focus()" autocomplete="off"
                        placeholder="Scan a barcode / IMEI, or type a SKU and press Enter"
                        class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 pl-10 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
                @if ($notice !== '')
                    <p class="mt-1.5 text-sm {{ $noticeIsError ? 'text-rose-600' : 'text-gray-500' }}">{{ $notice }}</p>
                @endif
            </div>

            <div class="flex flex-col sm:flex-row gap-3">
                <x-search-input model="search" placeholder="Search by name, SKU, brand, model…" class="flex-1" />
                <select wire:model.live="category" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm">
                    <option value="all">All categories</option>
                    <option value="mobile">Mobile</option>
                    <option value="laptop">Laptop</option>
                    <option value="ac">AC</option>
                    <option value="refrigerator">Refrigerator</option>
                    <option value="solar_inverter">Solar Inverter</option>
                    <option value="other">Other</option>
                </select>
            </div>
        </div>

        {{-- Unit picker — a serialized product needs the exact unit chosen --}}
        @if ($this->pickingProduct)
            <div class="rounded-2xl border border-walnut-200/60 bg-walnut-50/70 dark:bg-walnut-900/40 p-4 space-y-3" wire:key="unit-picker">
                <div class="flex items-center justify-between">
                    <p class="font-medium text-gray-900 dark:text-white">Which unit of {{ $this->pickingProduct->name }}?</p>
                    <button type="button" wire:click="cancelPicking" class="text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                </div>
                <div class="flex flex-wrap gap-2">
                    @forelse ($this->pickerSerials as $unit)
                        <button type="button" wire:click="addSerial({{ $unit->id }})" wire:key="unit-{{ $unit->id }}"
                            class="rounded-full border border-gray-300 bg-white px-3 py-1.5 font-mono text-xs text-gray-700 hover:border-walnut-400 hover:bg-walnut-50">
                            {{ $unit->serial_number }}
                        </button>
                    @empty
                        <p class="text-sm text-gray-400">No units left.</p>
                    @endforelse
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 md:grid-cols-3 gap-3" wire:loading.class="opacity-60" wire:target="search,category">
            @forelse ($this->products as $product)
                @php
                    $thumb = $product->getFirstMediaUrl(\App\Models\Product::MEDIA_COLLECTION, 'thumb');
                    $available = $product->availableQuantity();
                @endphp
                <button type="button" wire:click="addProduct({{ $product->id }})" wire:key="product-{{ $product->id }}"
                    @disabled($available === 0)
                    class="{{ $card }} p-3 text-left transition hover:border-walnut-400 disabled:cursor-not-allowed disabled:opacity-50">
                    <div class="flex items-start gap-3">
                        <div class="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                            @if ($thumb)
                                <img src="{{ $thumb }}" class="h-full w-full object-cover" alt="">
                            @else
                                <x-tenant-icon name="cube" class="h-5 w-5 text-gray-400" />
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="line-clamp-2 text-sm font-semibold leading-snug text-gray-900 dark:text-white">{{ $product->name }}</p>
                            <p class="truncate text-xs text-gray-500">{{ trim($product->brand.' '.$product->model) ?: ($product->sku ?: '—') }}</p>
                        </div>
                    </div>
                    <div class="mt-2 flex items-end justify-between">
                        <span class="text-sm font-semibold text-walnut-600 dark:text-walnut-400">{{ $money($product->cash_price) }}</span>
                        <span class="text-xs {{ $available === 0 ? 'text-rose-600' : ($available <= $product->reorder_level ? 'text-amber-600' : 'text-gray-500') }}">
                            {{ $available === 0 ? 'Out of stock' : $available.' in stock' }}
                        </span>
                    </div>
                </button>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center text-gray-400">
                    No products match.
                </div>
            @endforelse
        </div>

        @if ($this->products->count() === 48)
            <p class="text-center text-xs text-gray-400">Showing the first 48 — search to narrow it down.</p>
        @endif
    </div>

    {{-- Cart ────────────────────────────────────────────────────────── --}}
    <div class="xl:col-span-2">
        <div class="{{ $card }} p-4 space-y-4 xl:sticky xl:top-4">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 dark:text-white">
                    Sale
                    <span class="text-sm font-normal text-gray-400">({{ $cartCount }} {{ \Illuminate\Support\Str::plural('item', $cartCount) }})</span>
                </h3>
                @if (count($cart) > 0)
                    <button type="button" wire:click="clearCart" wire:confirm="Clear everything on this sale?"
                        class="text-xs font-medium text-rose-600 hover:underline">Clear</button>
                @endif
            </div>

            {{-- Customer --}}
            <div class="space-y-2">
                <x-input-label value="Customer" />
                <x-search-select search-method="searchCustomers" model="customer_id"
                    placeholder="Walk-in — search to attach a customer…" class="mt-1" />
                <x-input-error :messages="$errors->get('customer_id')" class="mt-1" />

                @if (! $customer_id)
                    <div class="grid grid-cols-2 gap-2">
                        <x-text-input wire:model="walkInName" placeholder="Name (optional)" class="block w-full text-sm" />
                        <x-text-input wire:model="walkInPhone" placeholder="Phone (optional)" class="block w-full text-sm" />
                    </div>
                @endif
            </div>

            {{-- Lines --}}
            <div class="space-y-2">
                @error('cart') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

                @forelse ($this->lines as $index => $line)
                    @php
                        $computed = $totals['lines'][$index];
                    @endphp
                    <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 p-3 space-y-2" wire:key="line-{{ $index }}-{{ $cart[$index]['product_id'] }}-{{ $cart[$index]['product_serial_id'] ?? 'x' }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $line['product']->name }}</p>
                                @if ($line['serial'])
                                    <p class="font-mono text-xs text-gray-500">{{ $line['serial']->serial_number }}</p>
                                @else
                                    <p class="text-xs text-gray-500">{{ $money($line['unit_price']) }} each</p>
                                @endif
                            </div>
                            <button type="button" wire:click="removeLine({{ $index }})" title="Remove"
                                class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600">
                                <x-tenant-icon name="x-mark" class="h-4 w-4" />
                                <span class="sr-only">Remove {{ $line['product']->name }}</span>
                            </button>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            @if ($line['product']->is_serialized)
                                <span class="text-xs text-gray-500">{{ $money($line['unit_price']) }}</span>
                            @else
                                <div class="inline-flex items-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900">
                                    <button type="button" wire:click="changeQuantity({{ $index }}, -1)" class="px-2.5 py-1 text-gray-600 hover:bg-gray-100">−</button>
                                    <input type="number" min="1" wire:model.blur="cart.{{ $index }}.quantity"
                                        class="w-12 border-0 bg-transparent p-1 text-center text-sm focus:ring-0">
                                    <button type="button" wire:click="changeQuantity({{ $index }}, 1)" class="px-2.5 py-1 text-gray-600 hover:bg-gray-100">+</button>
                                </div>
                            @endif

                            <div class="flex items-center gap-1">
                                <input type="number" min="0" step="0.01" placeholder="Discount"
                                    wire:model.live.debounce.500ms="cart.{{ $index }}.discount_value"
                                    class="w-24 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-1 text-right text-sm focus:border-walnut-400 focus:ring-walnut-400">
                                <select wire:model.live="cart.{{ $index }}.discount_type"
                                    class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 py-1 pl-2 pr-7 text-sm">
                                    <option value="fixed">Rs.</option>
                                    <option value="percent">%</option>
                                </select>
                            </div>

                            <div class="text-right">
                                @if (bccomp($computed['discount'], '0', 2) > 0)
                                    <p class="text-xs text-gray-400 line-through">{{ $money($computed['gross']) }}</p>
                                @endif
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $money($computed['net']) }}</p>
                            </div>
                        </div>

                        @foreach (['product_id', 'product_serial_id', 'quantity', 'discount_value'] as $field)
                            <x-input-error :messages="$errors->get('cart.'.$index.'.'.$field)" />
                        @endforeach
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 text-center text-sm text-gray-400">
                        Tap a product — or scan one — to start a sale.
                    </div>
                @endforelse
            </div>

            {{-- Totals --}}
            <dl class="space-y-1.5 border-t border-gray-100 dark:border-gray-700 pt-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Subtotal</dt>
                    <dd class="text-gray-900 dark:text-white">{{ $money($totals['subtotal']) }}</dd>
                </div>
                @if (bccomp($totals['line_discount_total'], '0', 2) > 0)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Item discounts</dt>
                        <dd class="text-rose-600">−{{ $money($totals['line_discount_total']) }}</dd>
                    </div>
                @endif
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-gray-500">Bill discount</dt>
                    <dd class="flex items-center gap-1">
                        <input type="number" min="0" step="0.01" placeholder="0"
                            wire:model.live.debounce.500ms="billDiscountValue"
                            class="w-24 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-1 text-right text-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <select wire:model.live="billDiscountType"
                            class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 py-1 pl-2 pr-7 text-sm">
                            <option value="fixed">Rs.</option>
                            <option value="percent">%</option>
                        </select>
                    </dd>
                </div>
                <x-input-error :messages="$errors->get('billDiscountValue')" class="text-right" />
                @if (bccomp($totals['bill_discount_amount'], '0', 2) > 0)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Bill discount applied</dt>
                        <dd class="text-rose-600">−{{ $money($totals['bill_discount_amount']) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t border-gray-100 dark:border-gray-700 pt-2 text-lg font-bold">
                    <dt class="text-gray-900 dark:text-white">Total</dt>
                    <dd class="text-walnut-600 dark:text-walnut-400">{{ $money($totals['total']) }}</dd>
                </div>
            </dl>

            {{-- Payments --}}
            <div class="space-y-3 border-t border-gray-100 dark:border-gray-700 pt-3">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Payment</p>

                @foreach ($payments as $index => $payment)
                    @php
                        $amount = $effective[$index]['amount'];
                        $tendered = \App\Support\PosCalculator::number($payment['tendered'] ?? '');
                        $change = bccomp($tendered, $amount, 2) > 0 ? bcsub($tendered, $amount, 2) : null;
                    @endphp
                    <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 p-3 space-y-2" wire:key="payment-{{ $index }}">
                        <div class="flex items-center gap-2">
                            @foreach ([\App\Support\PaymentMethod::CASH => 'Cash', \App\Support\PaymentMethod::BANK => 'Bank'] as $method => $label)
                                <button type="button" wire:click="setPaymentMethod({{ $index }}, '{{ $method }}')"
                                    @disabled($method === \App\Support\PaymentMethod::BANK && $this->banks->isEmpty())
                                    class="flex-1 rounded-lg border px-3 py-1.5 text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed
                                           {{ $payment['method'] === $method
                                                ? ($method === 'cash' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-sky-600 text-white border-sky-600')
                                                : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-gray-400' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                            @if (count($payments) > 1)
                                <button type="button" wire:click="removePayment({{ $index }})" title="Remove this payment"
                                    class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600">
                                    <x-tenant-icon name="x-mark" class="h-4 w-4" />
                                </button>
                            @endif
                        </div>

                        <div>
                            <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="payments.{{ $index }}.amount"
                                placeholder="{{ bccomp($amount, '0', 2) > 0 ? \App\Support\PosCalculator::number($amount) : 'Amount' }}"
                                class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-right shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                            <x-input-error :messages="$errors->get('payments.'.$index.'.amount')" class="mt-1" />
                        </div>

                        @if ($payment['method'] === 'cash')
                            <div>
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="payments.{{ $index }}.tendered"
                                    placeholder="Cash received (optional — to work out change)"
                                    class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-right text-sm shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                                @if ($change)
                                    <p class="mt-1 text-right text-sm font-semibold text-emerald-600">Change due: {{ $money($change) }}</p>
                                @endif
                                <x-input-error :messages="$errors->get('payments.'.$index.'.tendered')" class="mt-1" />
                            </div>
                        @else
                            <div class="space-y-2">
                                <select wire:model="payments.{{ $index }}.bank_id"
                                    class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                    <option value="">Select a bank…</option>
                                    @foreach ($this->banks as $bank)
                                        <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('payments.'.$index.'.bank_id')" />

                                <x-text-input wire:model="payments.{{ $index }}.reference" placeholder="Transaction / reference no. (optional)" class="block w-full text-sm" />

                                <div>
                                    <input type="file" wire:model="proofs.{{ $index }}" accept="image/png,image/jpeg,image/webp,application/pdf"
                                        class="block w-full text-xs text-gray-600 dark:text-gray-300 file:mr-3 file:rounded-lg file:border-0 file:bg-sky-50 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-sky-700 hover:file:bg-sky-100">
                                    <p wire:loading wire:target="proofs.{{ $index }}" class="mt-1 text-xs text-gray-400">Uploading…</p>
                                    <x-input-error :messages="$errors->get('proofs.'.$index)" class="mt-1" />
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach

                <button type="button" wire:click="addPayment"
                    class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 px-3 py-2 text-xs font-semibold text-gray-600 transition hover:border-walnut-400 hover:text-walnut-600">
                    <x-tenant-icon name="plus" class="h-4 w-4" /> Split payment
                </button>

                <x-input-error :messages="$errors->get('payments')" />
            </div>

            <div>
                <x-text-input wire:model="notes" placeholder="Note on this sale (optional)" class="block w-full text-sm" />
            </div>

            <button type="button" wire:click="checkout" wire:loading.attr="disabled" wire:target="checkout"
                @disabled(count($cart) === 0)
                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-5 py-3 text-base font-semibold text-white transition hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed">
                <svg wire:loading wire:target="checkout" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span wire:loading.remove wire:target="checkout">Complete Sale — {{ $money($totals['total']) }}</span>
                <span wire:loading wire:target="checkout">Completing…</span>
            </button>
        </div>
    </div>
</div>
