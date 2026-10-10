<?php

use App\Models\Agreement;
use App\Models\InstallmentSchedule;
use App\Models\Payment;
use App\Models\Sale;
use App\Support\Tenant;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    private function isSalesman(): bool
    {
        return auth()->user()->hasRole('Salesman');
    }

    #[Computed]
    public function myAgreementsCount(): int
    {
        return Agreement::where('status', 'active')
            ->when($this->isSalesman(), fn ($q) => $q->where('salesman_id', auth()->id()))
            ->count();
    }

    #[Computed]
    public function dueTodayCount(): int
    {
        return InstallmentSchedule::whereHas('agreement', fn ($q) => $q
                ->where('status', 'active')
                ->when($this->isSalesman(), fn ($q) => $q->where('salesman_id', auth()->id())))
            ->whereDate('due_date', today())
            ->whereIn('status', ['pending', 'partial'])
            ->count();
    }

    #[Computed]
    public function collectedToday(): float
    {
        return (float) Payment::whereDate('paid_at', today())
            ->when($this->isSalesman(), fn ($q) => $q->where('received_by', auth()->id()))
            ->sum('amount');
    }

    /** The Point of Sale is an add-on a shop opts into — nothing here shows without it. */
    #[Computed]
    public function posEnabled(): bool
    {
        return Tenant::current()?->posEnabled() ?? false;
    }

    /** Sales rung up today by this user (everyone's, for a manager or admin who lands here). */
    private function salesToday()
    {
        return Sale::whereDate('sold_at', today())
            ->when($this->isSalesman(), fn ($q) => $q->where('sold_by', auth()->id()));
    }

    #[Computed]
    public function salesTodayCount(): int
    {
        return $this->salesToday()->count();
    }

    /** What those sales came to, less anything already returned. */
    #[Computed]
    public function soldToday(): float
    {
        $sales = $this->salesToday();

        return (float) (clone $sales)->sum('total') - (float) (clone $sales)->sum('returned_total');
    }

    #[Computed]
    public function recentPayments()
    {
        return Payment::with('customer')
            ->when($this->isSalesman(), fn ($q) => $q->where('received_by', auth()->id()))
            ->latest('paid_at')
            ->limit(6)
            ->get();
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Good day, {{ auth()->user()->name }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Here's what's on today's collection round</p>
    @endslot

<div>

    <div class="grid grid-cols-3 gap-3 mb-6">
        <x-stat-card label="My Active" :value="$this->myAgreementsCount" color="walnut" />
        <x-stat-card label="Due Today" :value="$this->dueTodayCount" color="amber" />
        <x-stat-card label="Collected Today" :value="'Rs. '.number_format($this->collectedToday, 0)" color="emerald" />
    </div>

    @if ($this->posEnabled)
        <div class="grid grid-cols-2 gap-3 mb-3">
            <x-stat-card label="My Sales Today" :value="$this->salesTodayCount" color="walnut" />
            <x-stat-card label="Sold Today" :value="'Rs. '.number_format($this->soldToday, 0)" color="emerald" />
        </div>

        <a href="{{ route('tenant.pos.register') }}" wire:navigate
           class="flex items-center justify-between rounded-2xl bg-walnut-900 px-5 py-4 text-white shadow-lg shadow-walnut-900/20 mb-3">
            <span class="flex items-center gap-3 font-medium">
                <x-tenant-icon name="shopping-cart" class="h-6 w-6" /> New Counter Sale
            </span>
            <x-tenant-icon name="chevron-right" class="h-5 w-5" />
        </a>
    @endif

    <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.collections.desk') ? route('tenant.collections.desk') : '#' }}" wire:navigate
       class="flex items-center justify-between rounded-2xl bg-walnut-600 px-5 py-4 text-white shadow-lg shadow-walnut-600/20 mb-6">
        <span class="flex items-center gap-3 font-medium">
            <x-tenant-icon name="banknotes" class="h-6 w-6" /> Go to Collection Desk
        </span>
        <x-tenant-icon name="chevron-right" class="h-5 w-5" />
    </a>

    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">My Recent Collections</h3>
        <div class="space-y-2">
            @forelse ($this->recentPayments as $payment)
                <div class="flex items-center justify-between rounded-xl bg-gray-50 dark:bg-gray-900/40 px-4 py-3">
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $payment->customer->first_name }} {{ $payment->customer->last_name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $payment->receipt_number }} · {{ $payment->paid_at->format('d M, h:i A') }}</p>
                    </div>
                    <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">Rs. {{ number_format((float) $payment->amount, 0) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">No collections logged yet today.</p>
            @endforelse
        </div>
    </div>
</div>
