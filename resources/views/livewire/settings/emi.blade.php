<?php

use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public string $emi_price_basis;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $this->emi_price_basis = Tenant::current()?->emi_price_basis ?? 'selling';
    }

    public function setBasis(string $basis): void
    {
        abort_unless(in_array($basis, ['cost', 'selling'], true), 404);

        $shop = Tenant::current();
        abort_unless($shop, 403);

        $shop->update(['emi_price_basis' => $basis]);
        $this->emi_price_basis = $basis;

        session()->flash('status', 'EMI price basis updated — applies to agreements created from now on.');
    }
} ?>

@slot('header')
    <h1 class="text-xl font-semibold" style="color: var(--theme-bg-text)">Settings</h1>
    <p class="text-sm opacity-70" style="color: var(--theme-bg-text)">Choose which price the EMI calculator starts from</p>
@endslot

<div>
    {{-- Settings sub-nav --}}
    <div class="flex gap-1 border-b border-black/10 mb-6">
        <a href="{{ route('tenant.settings.appearance') }}" wire:navigate
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
            <x-tenant-icon name="swatch" class="h-4 w-4" />
            Appearance
        </a>
        <a href="{{ route('tenant.settings.staff') }}" wire:navigate
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
            <x-tenant-icon name="users" class="h-4 w-4" />
            Staff
        </a>
        <a href="{{ route('tenant.settings.banks') }}" wire:navigate
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
            <x-tenant-icon name="building-storefront" class="h-4 w-4" />
            Banks
        </a>
        <div class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2"
             style="color: var(--theme-accent); border-color: var(--theme-accent)">
            <x-tenant-icon name="calculator" class="h-4 w-4" />
            EMI Settings
        </div>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">EMI Price Basis</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">When creating an agreement, which price should "Product Price" start from once a product is selected? Staff can still adjust it by hand afterward.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <button type="button" wire:click="setBasis('selling')"
                class="text-left rounded-2xl border-2 p-4 transition {{ $emi_price_basis === 'selling' ? 'border-walnut-400 shadow-md' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300' }}">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900 dark:text-white">Selling Price</span>
                    @if ($emi_price_basis === 'selling')
                        <span class="inline-flex items-center gap-1 rounded-full bg-walnut-600 px-2 py-0.5 text-xs font-medium text-white">
                            <x-tenant-icon name="check-circle" class="h-3.5 w-3.5" /> Active
                        </span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">The product's cash/retail price — what a walk-in customer would pay outright. Most common choice.</p>
            </button>

            <button type="button" wire:click="setBasis('cost')"
                class="text-left rounded-2xl border-2 p-4 transition {{ $emi_price_basis === 'cost' ? 'border-walnut-400 shadow-md' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300' }}">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900 dark:text-white">Cost Price</span>
                    @if ($emi_price_basis === 'cost')
                        <span class="inline-flex items-center gap-1 rounded-full bg-walnut-600 px-2 py-0.5 text-xs font-medium text-white">
                            <x-tenant-icon name="check-circle" class="h-3.5 w-3.5" /> Active
                        </span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">What the shop paid for the product. Note: this price will then be visible to whoever creates the agreement, including Salesman.</p>
            </button>
        </div>
    </div>
</div>
