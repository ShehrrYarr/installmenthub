<?php

use App\Models\Shop;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.super-admin')] class extends Component
{
    #[Computed]
    public function totalShops(): int
    {
        return Shop::count();
    }

    #[Computed]
    public function activeShops(): int
    {
        return Shop::where('subscription_status', 'active')->count();
    }

    #[Computed]
    public function trialShops(): int
    {
        return Shop::where('subscription_status', 'trial')->count();
    }

    #[Computed]
    public function suspendedShops(): int
    {
        return Shop::where('subscription_status', 'suspended')->count();
    }

    #[Computed]
    public function mrr(): float
    {
        return (float) Shop::where('subscription_status', 'active')->sum('monthly_fee');
    }

    #[Computed]
    public function recentShops()
    {
        return Shop::with('owner')->latest()->limit(8)->get();
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Platform Overview</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">All tenants, subscription health, and billing at a glance</p>
    @endslot

<div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="Total Shops" :value="$this->totalShops" color="walnut" />
        <x-stat-card label="Active" :value="$this->activeShops" color="emerald" />
        <x-stat-card label="On Trial" :value="$this->trialShops" color="amber" />
        <x-stat-card label="Suspended" :value="$this->suspendedShops" color="rose" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1">
            <x-stat-card label="Monthly Recurring Revenue" :value="'Rs. '.number_format($this->mrr, 2)" color="sky" hint="Sum of active shops' monthly fee" />
        </div>

        <div class="lg:col-span-2 rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-900 dark:text-white">Recent Shops</h3>
                <a href="{{ \Illuminate\Support\Facades\Route::has('superadmin.shops.index') ? route('superadmin.shops.index') : '#' }}" wire:navigate class="text-sm text-walnut-600 dark:text-walnut-400 hover:text-walnut-900 dark:hover:text-walnut-200">View all</a>
            </div>

            <div class="space-y-2">
                @forelse ($this->recentShops as $shop)
                    <div class="flex items-center justify-between rounded-xl bg-gray-50 dark:bg-gray-900/40 px-4 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $shop->name }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $shop->owner?->name ?? 'No owner assigned' }}</p>
                        </div>
                        <x-status-badge :status="$shop->subscription_status" />
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No shops yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
