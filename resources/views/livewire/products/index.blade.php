<?php

use App\Models\Product;
use Livewire\Attributes\Url;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public string $category = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-products'), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $products = Product::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('sku', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")))
            ->when($this->category !== 'all', fn ($q) => $q->where('category', $this->category))
            ->withCount(['serials as available_serials_count' => fn ($q) => $q->where('status', 'in_stock')])
            ->latest()
            ->paginate(12);

        return ['products' => $products];
    }
} ?>

@slot('header')
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Products</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Catalog, pricing, and inventory</p>
            </div>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.products.create') ? route('tenant.products.create') : '#' }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                <x-tenant-icon name="plus" class="h-4 w-4" /> New Product
            </a>
        </div>
    @endslot

<div>

    <div class="space-y-4">
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <x-tenant-icon name="magnifying-glass" class="absolute left-3 top-2.5 h-5 w-5 text-gray-400" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name, SKU, brand…"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 pl-10 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
            </div>
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse ($products as $product)
                @php($thumb = $product->getFirstMediaUrl(\App\Models\Product::MEDIA_COLLECTION, 'thumb'))
                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-3">
                    <div class="flex items-start gap-3">
                        <div class="h-14 w-14 shrink-0 rounded-xl bg-gray-100 dark:bg-gray-700 overflow-hidden flex items-center justify-center">
                            @if ($thumb)
                                <img src="{{ $thumb }}" class="h-full w-full object-cover" alt="{{ $product->name }}">
                            @else
                                <x-tenant-icon name="cube" class="h-6 w-6 text-gray-400" />
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-gray-900 dark:text-white truncate">{{ $product->name }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $product->brand }} {{ $product->model }}</p>
                        </div>
                        <x-status-badge :status="$product->is_active ? 'active' : 'suspended'" />
                    </div>

                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2">
                            <dt class="text-xs text-gray-400">Cash Price</dt>
                            <dd class="font-medium text-gray-900 dark:text-white">Rs. {{ number_format((float) $product->cash_price, 2) }}</dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2">
                            <dt class="text-xs text-gray-400">In Stock</dt>
                            <dd class="font-medium {{ $product->available_serials_count <= $product->reorder_level ? 'text-rose-600' : 'text-gray-900 dark:text-white' }}">
                                {{ $product->is_serialized ? $product->available_serials_count : $product->stock_quantity }}
                            </dd>
                        </div>
                    </dl>

                    <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.products.edit') ? route('tenant.products.edit', $product) : '#' }}" wire:navigate
                       class="block text-center rounded-lg bg-gray-100 dark:bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200">
                        Manage
                    </a>
                </div>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center text-gray-400">
                    No products found.
                </div>
            @endforelse
        </div>

        {{ $products->links() }}
    </div>
</div>
