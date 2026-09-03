<?php

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    #[Validate('required|exists:vendors,id')]
    public ?int $vendor_id = null;

    #[Validate('required|string|max:100')]
    public string $invoice_number = '';

    #[Validate('required|date')]
    public string $order_date = '';

    #[Validate('required|in:cash,bank,credit')]
    public string $payment_mode = 'cash';

    public string $notes = '';

    /** @var array<int, array{product_id: ?int, quantity: int, cost_price: string, selling_cash_price: string}> */
    public array $items = [
        ['product_id' => null, 'quantity' => 1, 'cost_price' => '0', 'selling_cash_price' => '0'],
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-purchase-orders'), 403);

        $this->order_date = now()->toDateString();
    }

    public function addItem(): void
    {
        $lastIndex = array_key_last($this->items);

        if ($lastIndex !== null && ! $this->itemHasValidPrices($lastIndex)) {
            $this->validateOnly("items.{$lastIndex}.cost_price", $this->itemRules(), $this->itemMessages());
            $this->validateOnly("items.{$lastIndex}.selling_cash_price", $this->itemRules(), $this->itemMessages());

            return;
        }

        $this->items[] = ['product_id' => null, 'quantity' => 1, 'cost_price' => '0', 'selling_cash_price' => '0'];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function validateItemPrices(int $index): void
    {
        $this->validateOnly("items.{$index}.cost_price", $this->itemRules(), $this->itemMessages());
        $this->validateOnly("items.{$index}.selling_cash_price", $this->itemRules(), $this->itemMessages());
    }

    private function itemHasValidPrices(int $index): bool
    {
        $item = $this->items[$index];

        return bccomp((string) ($item['cost_price'] ?: '0'), '0.01', 2) >= 0
            && bccomp((string) ($item['selling_cash_price'] ?: '0'), '0.01', 2) >= 0;
    }

    /** @return array<string, string> */
    private function itemRules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.cost_price' => 'required|numeric|min:0.01',
            'items.*.selling_cash_price' => 'required|numeric|min:0.01',
        ];
    }

    /** @return array<string, string> */
    private function itemMessages(): array
    {
        return [
            'items.*.cost_price.required' => 'Enter a cost price.',
            'items.*.cost_price.min' => 'Cost price must be greater than 0.',
            'items.*.selling_cash_price.required' => 'Enter a selling cash price.',
            'items.*.selling_cash_price.min' => 'Selling cash price must be greater than 0.',
        ];
    }

    #[Computed]
    public function total(): string
    {
        return (string) collect($this->items)->reduce(
            fn ($carry, $item) => bcadd($carry, bcmul((string) $item['quantity'], (string) ($item['cost_price'] ?: '0'), 2), 2),
            '0.00'
        );
    }

    /** @return array<int, array{id: int, label: string, sublabel: string}> */
    public function searchVendors(string $query): array
    {
        if (mb_strlen($query) < 2) {
            return [];
        }

        return Vendor::where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', "%{$query}%")
                ->orWhere('contact_person', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%"))
            ->orderBy('name')
            ->limit(15)
            ->get()
            ->map(fn ($vendor) => [
                'id' => $vendor->id,
                'label' => $vendor->name,
                'sublabel' => $vendor->phone,
            ])
            ->all();
    }

    /** @return array<int, array{id: int, label: string, sublabel: string}> */
    public function searchProducts(string $query): array
    {
        if (mb_strlen($query) < 2) {
            return [];
        }

        return Product::where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', "%{$query}%")
                ->orWhere('sku', 'like', "%{$query}%")
                ->orWhere('brand', 'like', "%{$query}%"))
            ->orderBy('name')
            ->limit(15)
            ->get()
            ->map(fn ($product) => [
                'id' => $product->id,
                'label' => $product->name,
                'sublabel' => $product->sku ?: $product->brand,
            ])
            ->all();
    }

    public function save(): void
    {
        $this->validate();
        $this->validate($this->itemRules(), $this->itemMessages());

        // `exists:products,id` (and the vendor_id rule above) run a plain DB
        // query that bypasses ShopScope, so they'd accept another shop's
        // vendor/product id. Re-fetch through the tenant-scoped Eloquent
        // models to confirm they actually belong to this shop.
        if (! Vendor::find($this->vendor_id)) {
            $this->addError('vendor_id', 'Select a valid vendor.');

            return;
        }

        $productIds = collect($this->items)->pluck('product_id')->unique();
        if (Product::whereIn('id', $productIds)->count() !== $productIds->count()) {
            $this->addError('items', 'One or more selected products are invalid.');

            return;
        }

        $order = DB::transaction(function () {
            $total = $this->total;

            $order = PurchaseOrder::create([
                'vendor_id' => $this->vendor_id,
                'po_number' => 'PO-'.now()->format('ymd').'-'.random_int(1000, 9999),
                'invoice_number' => $this->invoice_number,
                'order_date' => $this->order_date,
                'payment_mode' => $this->payment_mode,
                'status' => 'draft',
                'subtotal' => $total,
                'total_amount' => $total,
                'notes' => $this->notes ?: null,
                'created_by' => auth()->id(),
            ]);

            foreach ($this->items as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'cost_price' => $item['cost_price'],
                    'selling_cash_price' => $item['selling_cash_price'],
                    'subtotal' => bcmul((string) $item['quantity'], (string) $item['cost_price'], 2),
                ]);
            }

            return $order;
        });

        session()->flash('status', "Purchase order {$order->po_number} created. Receive stock to register serial numbers.");

        $this->redirect(
            \Illuminate\Support\Facades\Route::has('tenant.purchase-orders.receive') ? route('tenant.purchase-orders.receive', $order) : '/',
            navigate: true
        );
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">New Purchase Order</h1>
    @endslot

<div>

    <form wire:submit="save" class="max-w-4xl space-y-6">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="lg:col-span-2">
                    <x-input-label for="vendor_id" value="Vendor" />
                    <x-search-select search-method="searchVendors" model="vendor_id"
                        placeholder="Search by name or phone…" class="mt-1" />
                    <x-input-error :messages="$errors->get('vendor_id')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="invoice_number" value="Invoice #" />
                    <x-text-input id="invoice_number" wire:model="invoice_number" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="order_date" value="Order Date" />
                    <x-text-input id="order_date" type="date" wire:model="order_date" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="payment_mode" value="Payment Mode" />
                    <select id="payment_mode" wire:model="payment_mode" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <option value="cash">Cash</option>
                        <option value="bank">Bank</option>
                        <option value="credit">Credit</option>
                    </select>
                </div>
                <div class="lg:col-span-3">
                    <x-input-label for="notes" value="Notes" />
                    <x-text-input id="notes" wire:model="notes" class="mt-1 block w-full" />
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 dark:text-white">Line Items</h3>
                <button type="button" wire:click="addItem" class="text-sm font-medium text-walnut-400 hover:text-walnut-400">+ Add Item</button>
            </div>

            <div class="space-y-3">
                @foreach ($items as $index => $item)
                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end rounded-xl bg-gray-50 dark:bg-gray-900/40 p-3">
                        <div class="sm:col-span-4">
                            <x-input-label value="Product" />
                            <x-search-select search-method="searchProducts" model="items.{{ $index }}.product_id"
                                placeholder="Search by name, SKU, or brand…" class="mt-1 text-sm" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label value="Qty" />
                            <x-text-input type="number" min="1" wire:model="items.{{ $index }}.quantity" class="mt-1 block w-full" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label value="Cost Price" />
                            <x-text-input type="number" step="0.01"
                                wire:model="items.{{ $index }}.cost_price"
                                wire:blur="validateItemPrices({{ $index }})"
                                class="mt-1 block w-full {{ $errors->has('items.'.$index.'.cost_price') ? 'border-rose-500 focus:border-rose-500 focus:ring-rose-500' : '' }}" />
                            <x-input-error :messages="$errors->get('items.'.$index.'.cost_price')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label value="Selling Cash Price" />
                            <x-text-input type="number" step="0.01"
                                wire:model="items.{{ $index }}.selling_cash_price"
                                wire:blur="validateItemPrices({{ $index }})"
                                class="mt-1 block w-full {{ $errors->has('items.'.$index.'.selling_cash_price') ? 'border-rose-500 focus:border-rose-500 focus:ring-rose-500' : '' }}" />
                            <x-input-error :messages="$errors->get('items.'.$index.'.selling_cash_price')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-1 flex justify-end">
                            @if (count($items) > 1)
                                <button type="button" wire:click="removeItem({{ $index }})" class="h-9 w-9 flex items-center justify-center rounded-lg text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40">
                                    <x-tenant-icon name="x-mark" class="h-4 w-4" />
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
                <x-input-error :messages="$errors->get('items')" class="mt-1" />
            </div>

            <div class="flex justify-end border-t border-gray-100 dark:border-gray-700 pt-4">
                <p class="text-sm text-gray-500">Total: <span class="text-lg font-semibold text-gray-900 dark:text-white">Rs. {{ number_format((float) $this->total, 2) }}</span></p>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-walnut-400">Save Purchase Order</button>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.purchase-orders.index') ? route('tenant.purchase-orders.index') : '/' }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
    </form>
</div>
