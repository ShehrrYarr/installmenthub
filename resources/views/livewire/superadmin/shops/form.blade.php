<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.super-admin')] class extends Component
{
    public ?Shop $shop = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $slug = '';

    /** Once true, typing in Shop Name stops overwriting the slug — set the moment the slug is edited by hand, or loaded from an existing shop. */
    public bool $slugManuallyEdited = false;

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $city = '';

    #[Validate('required|in:trial,active,suspended')]
    public string $subscription_status = 'trial';

    #[Validate('required|in:monthly,yearly')]
    public string $billing_cycle = 'monthly';

    #[Validate('required|integer|min:0')]
    public string $monthly_fee = '0';

    #[Validate('required|numeric|min:0')]
    public string $default_interest_rate = '12';

    #[Validate('required|integer|min:0')]
    public string $default_processing_fee = '0';

    #[Validate('required|in:daily,fixed')]
    public string $penalty_type = 'daily';

    #[Validate('required|integer|min:0')]
    public string $penalty_rate = '0';

    #[Validate('required|integer|min:0')]
    public int $grace_period_days = 0;

    // Owner account — only set/used when provisioning a new shop.
    public string $owner_name = '';

    public string $owner_email = '';

    public string $owner_password = '';

    public function mount(?Shop $shop = null): void
    {
        if ($shop?->exists) {
            $this->shop = $shop;
            $this->slug = $shop->slug;
            $this->slugManuallyEdited = true;
            // Nullable columns cast to '' before fill() — these are typed
            // `string` properties, and Livewire's direct property assignment
            // throws a TypeError on a null value. grace_period_days is int
            // (not string) and NOT NULL in the schema, so it passes through
            // unchanged.
            $this->fill([
                ...collect($shop->only([
                    'name', 'phone', 'email', 'address', 'city', 'subscription_status', 'billing_cycle',
                    'monthly_fee', 'default_interest_rate', 'default_processing_fee',
                    'penalty_type', 'penalty_rate',
                ]))->map(fn ($value) => $value ?? '')->all(),
                ...$shop->only(['grace_period_days']),
            ]);
        }
    }

    public function updatedName(): void
    {
        if (! $this->slugManuallyEdited) {
            $this->slug = Str::slug($this->name);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugManuallyEdited = true;
        $this->slug = Str::slug($this->slug);
    }

    public function save(): void
    {
        $this->validate();

        $this->validate([
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('shops', 'slug')->ignore($this->shop?->id),
            ],
        ], [
            'slug.regex' => 'The URL slug may only contain lowercase letters, numbers, and hyphens.',
        ]);

        if (! $this->shop) {
            $this->validate([
                'owner_name' => 'required|string|max:255',
                'owner_email' => 'required|email|unique:users,email',
                'owner_password' => 'required|string|min:8',
            ]);
        }

        DB::transaction(function () {
            $data = collect($this->all())->only([
                'name', 'slug', 'phone', 'email', 'address', 'city', 'subscription_status', 'billing_cycle',
                'monthly_fee', 'default_interest_rate', 'default_processing_fee',
                'penalty_type', 'penalty_rate', 'grace_period_days',
            ])->toArray();

            if ($this->shop) {
                $this->shop->update($data);
            } else {
                $data['trial_ends_at'] = $this->subscription_status === 'trial' ? now()->addDays(14) : null;

                $this->shop = Shop::create($data);

                $owner = User::create([
                    'name' => $this->owner_name,
                    'email' => $this->owner_email,
                    'password' => bcrypt($this->owner_password),
                    'shop_id' => $this->shop->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
                $owner->assignRole('Shop Admin');

                $this->shop->update(['owner_user_id' => $owner->id]);
            }

            session()->flash('status', "Shop {$this->shop->name} saved.");

            $this->redirect(
                \Illuminate\Support\Facades\Route::has('superadmin.shops.index') ? route('superadmin.shops.index') : '/',
                navigate: true
            );
        });
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $shop?->exists ? 'Edit Shop' : 'Provision New Shop' }}</h1>
    @endslot

<div>

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Shop Details</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Shop Name</label>
                    <input wire:model.live.debounce.300ms="name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">URL Slug</label>
                    <input wire:model.live.debounce.300ms="slug" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400 font-mono text-sm">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Login URL: {{ url('/'.($slug ?: '{slug}').'/login') }}</p>
                    <x-input-error :messages="$errors->get('slug')" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                    <input wire:model="phone" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input type="email" wire:model="email" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
                <div class="sm:col-span-2">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Address</label>
                    <input wire:model="address" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">City</label>
                    <input wire:model="city" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
            </div>
        </div>

        @unless ($shop?->exists)
            <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
                <h3 class="font-semibold text-gray-900 dark:text-white">Owner Account</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Owner Name</label>
                        <input wire:model="owner_name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <x-input-error :messages="$errors->get('owner_name')" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Owner Email</label>
                        <input type="email" wire:model="owner_email" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <x-input-error :messages="$errors->get('owner_email')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Temporary Password</label>
                        <input type="password" wire:model="owner_password" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <x-input-error :messages="$errors->get('owner_password')" class="mt-1" />
                    </div>
                </div>
            </div>
        @endunless

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Subscription & Billing</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                    <select wire:model="subscription_status" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                        <option value="trial">Trial</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Billing Cycle</label>
                    <select wire:model="billing_cycle" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Monthly Fee</label>
                    <input type="number" step="1" wire:model="monthly_fee" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">EMI & Penalty Defaults</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Default Interest Rate (%)</label>
                    <input type="number" step="0.01" wire:model="default_interest_rate" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Default Processing Fee</label>
                    <input type="number" step="1" wire:model="default_processing_fee" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Penalty Type</label>
                    <select wire:model="penalty_type" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                        <option value="daily">Daily</option>
                        <option value="fixed">Fixed</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Penalty Rate</label>
                    <input type="number" step="1" wire:model="penalty_rate" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Grace Period (days)</label>
                    <input type="number" wire:model="grace_period_days" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-walnut-400">Save Shop</button>
            <a href="{{ \Illuminate\Support\Facades\Route::has('superadmin.shops.index') ? route('superadmin.shops.index') : '/' }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">Cancel</a>
        </div>
    </form>
</div>
