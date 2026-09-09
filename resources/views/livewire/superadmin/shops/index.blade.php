<?php

use App\Models\Shop;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.super-admin')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = 'all';

    /** Shop currently being renewed in the reactivation dialog. */
    public ?Shop $renewing = null;

    #[Validate('required|integer|min:0')]
    public string $renewalAmount = '0';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function suspend(int $shopId): void
    {
        Shop::findOrFail($shopId)->update(['subscription_status' => 'suspended', 'suspended_at' => now()]);
    }

    public function startReactivation(int $shopId): void
    {
        $this->renewing = Shop::findOrFail($shopId);
        $this->renewalAmount = (string) (int) $this->renewing->annual_fee;
        $this->resetErrorBag();
    }

    public function cancelReactivation(): void
    {
        $this->renewing = null;
        $this->resetErrorBag();
    }

    public function confirmReactivation(): void
    {
        $this->validate();

        $shop = $this->renewing;

        abort_unless($shop, 404);

        [$periodStart, $periodEnd] = $shop->nextSubscriptionPeriod();

        $shop->subscriptionPayments()->create([
            'amount' => $this->renewalAmount,
            'paid_on' => today(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'notes' => 'Renewal',
            'recorded_by' => auth()->id(),
        ]);

        $shop->update([
            'subscription_status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
            'subscription_started_at' => $shop->subscription_started_at ?? now(),
            'next_billing_date' => $periodEnd,
        ]);

        session()->flash('status', "{$shop->name} reactivated until {$periodEnd->format('d M Y')}.");

        $this->renewing = null;
    }

    public function with(): array
    {
        $shops = Shop::query()
            ->with('owner')
            ->withSum('subscriptionPayments as subscription_paid_total', 'amount')
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
        @if (session('status'))
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if ($renewing)
            @php($period = $renewing->nextSubscriptionPeriod())
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" wire:key="renew-{{ $renewing->id }}">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-gray-800 p-5 shadow-xl space-y-4">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">Reactivate {{ $renewing->name }}</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            How much did this shop pay? Reactivating covers
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $period[0]->format('d M Y') }} — {{ $period[1]->format('d M Y') }}</span>.
                        </p>
                    </div>

                    <form wire:submit="confirmReactivation" class="space-y-4" wire:loading.class="opacity-50 pointer-events-none" wire:target="confirmReactivation">
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Amount Received</label>
                            <input type="number" step="1" wire:model="renewalAmount" autofocus
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                            <x-input-error :messages="$errors->get('renewalAmount')" class="mt-1" />
                            <p class="mt-1 text-xs text-gray-400">Defaults to the agreed annual fee — change it if they paid a different amount.</p>
                        </div>

                        <div class="flex items-center gap-3">
                            <button type="submit" wire:loading.attr="disabled" wire:target="confirmReactivation"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed">
                                <svg wire:loading wire:target="confirmReactivation" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span wire:loading.remove wire:target="confirmReactivation">Record Payment & Activate</span>
                                <span wire:loading wire:target="confirmReactivation">Activating…</span>
                            </button>
                            <button type="button" wire:click="cancelReactivation" wire:loading.attr="disabled" wire:target="confirmReactivation"
                                class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 disabled:opacity-50">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

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
                            <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Annual Fee</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Paid to Date</th>
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
                                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">Rs. {{ number_format((float) $shop->annual_fee, 0) }}</td>
                                <td class="px-4 py-3 text-right font-medium text-emerald-600 dark:text-emerald-400">Rs. {{ number_format((float) $shop->subscription_paid_total, 0) }}</td>
                                <td class="px-4 py-3">
                                    <x-status-badge :status="$shop->subscription_status" />
                                    @if ($shop->next_billing_date)
                                        <p class="mt-1 text-xs {{ $shop->next_billing_date->isPast() ? 'text-rose-500' : 'text-gray-400' }}">
                                            {{ $shop->next_billing_date->isPast() ? 'Expired' : 'Renews' }} {{ $shop->next_billing_date->format('d M Y') }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right space-x-3">
                                    <a href="{{ route('tenant.dashboard', $shop) }}" wire:navigate class="text-sky-600 dark:text-sky-400 hover:text-sky-800 dark:hover:text-sky-300 font-medium">Visit</a>
                                    <a href="{{ route('superadmin.shops.staff', $shop) }}" wire:navigate class="text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 font-medium">Staff</a>
                                    <a href="{{ \Illuminate\Support\Facades\Route::has('superadmin.shops.edit') ? route('superadmin.shops.edit', $shop) : '#' }}" wire:navigate class="text-walnut-600 dark:text-walnut-400 hover:text-walnut-900 dark:hover:text-walnut-200 font-medium">Edit</a>
                                    @if ($shop->subscription_status === 'suspended')
                                        <button wire:click="startReactivation({{ $shop->id }})" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-300 font-medium">Reactivate</button>
                                    @else
                                        <button wire:click="suspend({{ $shop->id }})" wire:confirm="Suspend this shop?" class="text-rose-600 dark:text-rose-400 hover:text-rose-800 dark:hover:text-rose-300 font-medium">Suspend</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No shops yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $shops->links() }}
    </div>
</div>
