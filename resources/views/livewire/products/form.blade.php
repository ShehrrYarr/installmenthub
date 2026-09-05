<?php

use App\Models\Product;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithFileUploads;

    public ?Product $product = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $sku = '';

    #[Validate('required|in:mobile,laptop,ac,refrigerator,solar_inverter,other')]
    public string $category = 'other';

    public string $brand = '';

    public string $model = '';

    public string $description = '';

    #[Validate('required|integer|min:0')]
    public string $cost_price = '0';

    #[Validate('required|integer|min:0')]
    public string $cash_price = '0';

    public bool $is_serialized = true;

    public int $reorder_level = 2;

    #[Validate([
        'newPhotos' => 'array|max:5',
        'newPhotos.*' => 'image|max:4096',
    ])]
    public array $newPhotos = [];

    public function mount(?Product $product = null): void
    {
        abort_unless(auth()->user()->can('manage-products'), 403);

        if ($product?->exists) {
            $this->product = $product;
            // Nullable columns cast to '' before fill() — these are typed
            // `string` properties, and Livewire's direct property assignment
            // throws a TypeError on a null value. is_serialized/reorder_level
            // are bool/int (not string) and NOT NULL in the schema, so they
            // pass through unchanged.
            $this->fill([
                ...collect($product->only([
                    'name', 'sku', 'category', 'brand', 'model', 'description', 'cost_price', 'cash_price',
                ]))->map(fn ($value) => $value ?? '')->all(),
                ...$product->only(['is_serialized', 'reorder_level']),
            ]);
        }
    }

    public function save(): void
    {
        $this->validate([
            'sku' => [
                'nullable', 'string', 'max:255',
                Rule::unique('products', 'sku')
                    ->where('shop_id', auth()->user()->shop_id)
                    ->ignore($this->product?->id),
            ],
        ]);
        $this->validate();

        $data = collect($this->all())->only([
            'name', 'sku', 'category', 'brand', 'model', 'description',
            'cost_price', 'cash_price', 'is_serialized', 'reorder_level',
        ])->toArray();
        $data['sku'] = $data['sku'] ?: null;

        $this->product = $this->product
            ? tap($this->product)->update($data)
            : Product::create($data);

        $existing = $this->product->getMedia(Product::MEDIA_COLLECTION)->count();

        foreach ($this->newPhotos as $file) {
            if ($existing >= Product::MAX_MEDIA_FILES) {
                break;
            }

            $this->product->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection(Product::MEDIA_COLLECTION);

            $existing++;
        }

        $this->newPhotos = [];

        session()->flash('status', 'Product saved.');

        $this->redirect(
            \Illuminate\Support\Facades\Route::has('tenant.products.index') ? route('tenant.products.index') : '/',
            navigate: true
        );
    }

    public function removeMedia(int $mediaId): void
    {
        $this->product?->media()->whereKey($mediaId)->first()?->delete();
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">
            {{ $product?->exists ? 'Edit Product' : 'New Product' }}
        </h1>
    @endslot

<div>

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Details</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-input-label for="name" value="Product Name" />
                    <x-text-input id="name" wire:model="name" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="category" value="Category" />
                    <select id="category" wire:model="category" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <option value="mobile">Mobile</option>
                        <option value="laptop">Laptop</option>
                        <option value="ac">AC</option>
                        <option value="refrigerator">Refrigerator</option>
                        <option value="solar_inverter">Solar Inverter</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="sku" value="SKU (optional)" />
                    <x-text-input id="sku" wire:model="sku" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('sku')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="brand" value="Brand" />
                    <x-text-input id="brand" wire:model="brand" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="model" value="Model" />
                    <x-text-input id="model" wire:model="model" class="mt-1 block w-full" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="description" value="Description" />
                    <textarea id="description" wire:model="description" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400"></textarea>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Pricing & Inventory</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="cost_price" value="Cost Price" />
                    <x-text-input id="cost_price" type="number" step="1" wire:model="cost_price" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('cost_price')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="cash_price" value="Selling Cash Price" />
                    <x-text-input id="cash_price" type="number" step="1" wire:model="cash_price" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('cash_price')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="reorder_level" value="Reorder Level" />
                    <x-text-input id="reorder_level" type="number" wire:model="reorder_level" class="mt-1 block w-full" />
                </div>
                <div class="flex items-center gap-2 pt-6">
                    <input id="is_serialized" type="checkbox" wire:model="is_serialized" class="rounded border-gray-300 text-walnut-600 focus:ring-walnut-400">
                    <label for="is_serialized" class="text-sm text-gray-700 dark:text-gray-300">Tracks IMEI / Serial Numbers</label>
                </div>
            </div>

            @if ($product?->exists)
                <p class="text-xs text-gray-400">
                    Stock and serial numbers are managed through Purchase Orders — see
                    <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.purchase-orders.index') ? route('tenant.purchase-orders.index') : '#' }}" wire:navigate class="text-walnut-400 hover:underline">Purchase Orders</a>.
                </p>
            @endif
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Photos</h3>
            <p class="text-xs text-gray-400 mb-3">Thumbnails, serial number tags, unboxing/condition shots — up to 5 files.</p>

            <x-media-dropzone
                model="newPhotos"
                :existing="$product?->getMedia(\App\Models\Product::MEDIA_COLLECTION) ?? []"
                :max-files="\App\Models\Product::MAX_MEDIA_FILES"
                :max-size-mb="4"
                accept="image/png,image/jpeg,image/webp"
                remove-method="removeMedia"
                label="Product Gallery"
            />
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-walnut-400" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save Product</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.products.index') ? route('tenant.products.index') : '/' }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
    </form>
</div>
