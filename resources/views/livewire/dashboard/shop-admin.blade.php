<?php

use App\Models\Agreement;
use App\Models\InstallmentSchedule;
use App\Models\Product;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    #[Computed]
    public function activeAgreements(): int
    {
        return Agreement::where('status', 'active')->count();
    }

    #[Computed]
    public function dueToday(): float
    {
        return (float) InstallmentSchedule::whereHas('agreement', fn ($q) => $q->where('status', 'active'))
            ->whereDate('due_date', today())
            ->whereIn('status', ['pending', 'partial'])
            ->sum('total_due');
    }

    #[Computed]
    public function overdueAmount(): float
    {
        return (float) InstallmentSchedule::where('status', 'overdue')
            ->selectRaw('COALESCE(SUM(total_due - amount_paid), 0) as total')
            ->value('total');
    }

    #[Computed]
    public function lowStockCount(): int
    {
        return Product::whereColumn('stock_quantity', '<=', 'reorder_level')->where('is_active', true)->count();
    }

    #[Computed]
    public function recentAgreements()
    {
        return Agreement::with('customer')->latest()->limit(6)->get();
    }

    #[Computed]
    public function overdueSchedules()
    {
        return InstallmentSchedule::with('agreement.customer')
            ->where('status', 'overdue')
            ->orderBy('due_date')
            ->limit(6)
            ->get();
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Dashboard</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ \App\Support\Tenant::current()?->name }}</p>
    @endslot

<div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="Active Agreements" :value="$this->activeAgreements" color="walnut" />
        <x-stat-card label="Due Today" :value="'Rs. '.number_format($this->dueToday, 0)" color="amber" />
        <x-stat-card label="Overdue" :value="'Rs. '.number_format($this->overdueAmount, 0)" color="rose" />
        <x-stat-card label="Low Stock Items" :value="$this->lowStockCount" color="sky" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Recent Agreements</h3>
            <div class="space-y-2">
                @forelse ($this->recentAgreements as $agreement)
                    <div class="flex items-center justify-between rounded-xl bg-gray-50 dark:bg-gray-900/40 px-4 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $agreement->agreement_number }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $agreement->customer->first_name }} {{ $agreement->customer->last_name }}</p>
                        </div>
                        <x-status-badge :status="$agreement->status" />
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No agreements yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border border-rose-200/60 dark:border-rose-900/60 bg-rose-50/60 dark:bg-rose-950/30 backdrop-blur-xl shadow-lg p-5">
            <h3 class="font-semibold text-rose-900 dark:text-rose-200 mb-4">Overdue Installments</h3>
            <div class="space-y-2">
                @forelse ($this->overdueSchedules as $schedule)
                    <div class="flex items-center justify-between rounded-xl bg-white/70 dark:bg-gray-900/40 px-4 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $schedule->agreement->agreement_number }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $schedule->agreement->customer->first_name }} · due {{ $schedule->due_date->format('d M') }}</p>
                        </div>
                        <span class="text-sm font-semibold text-rose-600 dark:text-rose-400">Rs. {{ number_format((float) $schedule->balanceRemaining(), 0) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-emerald-600 dark:text-emerald-400">Nothing overdue.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
