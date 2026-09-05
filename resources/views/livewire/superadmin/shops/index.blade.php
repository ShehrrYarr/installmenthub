<?php

use App\Models\Shop;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.super-admin')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function suspend(int $shopId): void
    {
        Shop::findOrFail($shopId)->update(['subscription_status' => 'suspended', 'suspended_at' => now()]);
    }

    public function reactivate(int $shopId): void
    {
        Shop::findOrFail($shopId)->update(['subscription_status' => 'active', 'suspended_at' => null, 'suspension_reason' => null]);
    }

    public function with(): array
    {
        $shops = Shop::query()
            ->with('owner')
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->status !== 'all', fn ($q) => $q->where('subscription_status', $this->status))
            ->latest()
            ->paginate(15);

        return ['shops' => $shops];
    }
} ?>

@slot('header')
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Shops</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Provision tenants and manage subscriptions</p>
            </div>
            <a href="{{ \Illuminate\Support\Facades\Route::has('superadmin.shops.create') ? route('superadmin.shops.create') : '#' }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                <x-tenant-icon name="plus" class="h-4 w-4" /> New Shop
            </a>
        </div>
    @endslot

<div>

    <div class="space-y-4">
        <div class="flex flex-col sm:flex-row gap-3">
            <x-search-input model="search" placeholder="Search shops…" class="w-full sm:w-80" />
            <select wire:model.live="status" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                <option value="all">All statuses</option>
                <option value="trial">Trial</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
            </select>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden" wire:loading.class="opacity-50 pointer-events-none" wire:target="search">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/80">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Shop</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">URL Slug</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Owner</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Monthly Fee</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($shops as $shop)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $shop->name }}</td>
                                <td class="px-4 py-3">
                                    <code class="rounded bg-gray-100 dark:bg-gray-900/60 px-1.5 py-0.5 text-xs text-gray-600 dark:text-gray-300">{{ $shop->slug }}</code>
                                </td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $shop->owner?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">Rs. {{ number_format((float) $shop->monthly_fee, 0) }}</td>
                                <td class="px-4 py-3"><x-status-badge :status="$shop->subscription_status" /></td>
                                <td class="px-4 py-3 text-right space-x-3">
                                    <a href="{{ route('tenant.dashboard', $shop) }}" wire:navigate class="text-sky-600 dark:text-sky-400 hover:text-sky-800 dark:hover:text-sky-300 font-medium">Visit</a>
                                    <a href="{{ route('superadmin.shops.staff', $shop) }}" wire:navigate class="text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 font-medium">Staff</a>
                                    <a href="{{ \Illuminate\Support\Facades\Route::has('superadmin.shops.edit') ? route('superadmin.shops.edit', $shop) : '#' }}" wire:navigate class="text-walnut-600 dark:text-walnut-400 hover:text-walnut-900 dark:hover:text-walnut-200 font-medium">Edit</a>
                                    @if ($shop->subscription_status === 'suspended')
                                        <button wire:click="reactivate({{ $shop->id }})" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-300 font-medium">Reactivate</button>
                                    @else
                                        <button wire:click="suspend({{ $shop->id }})" wire:confirm="Suspend this shop?" class="text-rose-600 dark:text-rose-400 hover:text-rose-800 dark:hover:text-rose-300 font-medium">Suspend</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No shops yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $shops->links() }}
    </div>
</div>
