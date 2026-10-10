<?php

use App\Models\Sale;
use App\Models\User;
use App\Support\PosReports;
use App\Support\Tenant;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** all | outstanding (a balance is owed) | overdue (owed and past its due date) */
    #[Url]
    public string $filter = 'all';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $salesman = '';

    /** all | cash | bank — sales that took at least one payment by that method. */
    #[Url]
    public string $method = 'all';

    public function mount(): void
    {
        abort_unless(Tenant::current()?->posEnabled(), 404);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'filter', 'from', 'to', 'salesman', 'method'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'filter', 'from', 'to', 'salesman', 'method']);
        $this->resetPage();
    }

    /** Anyone who has rung up a sale in this shop. */
    #[Computed]
    public function salesmen()
    {
        return User::where('shop_id', Tenant::id())
            ->whereIn('id', Sale::query()->whereNotNull('sold_by')->select('sold_by'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** The sales matching every filter — no eager loads, so it can also be summed. */
    private function filtered()
    {
        $term = $this->search;

        // Mirrors Sale::balanceDue() — see PosReports::balanceSql().
        $owed = PosReports::balanceSql().' > 0';

        return Sale::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('sale_number', 'like', "%{$term}%")
                ->orWhere('walk_in_name', 'like', "%{$term}%")
                ->orWhere('walk_in_phone', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c
                    ->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"))))
            ->when(in_array($this->filter, ['outstanding', 'overdue'], true), fn ($q) => $q->whereRaw($owed))
            ->when($this->filter === 'overdue', fn ($q) => $q->whereDate('due_date', '<', today()))
            ->when($this->from !== '', fn ($q) => $q->whereDate('sold_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('sold_at', '<=', $this->to))
            ->when($this->salesman !== '', fn ($q) => $q->where('sold_by', (int) $this->salesman))
            ->when(in_array($this->method, ['cash', 'bank'], true), fn ($q) => $q
                ->whereHas('payments', fn ($p) => $p->where('payment_mode', $this->method)));
    }

    #[Computed]
    public function totals(): array
    {
        return PosReports::totals($this->filtered());
    }

    public function with(): array
    {
        $sales = $this->filtered()
            ->with(['customer', 'seller', 'payments.bank'])
            ->withCount('items')
            ->selectRaw('sales.*, '.PosReports::costSql().' as kept_cost')
            ->latest('sold_at')
            ->latest('id')
            ->paginate(15);

        return ['sales' => $sales];
    }
} ?>

@php
    $money = fn ($value) => \App\Support\PosCalculator::money((string) $value);
    $showProfit = \App\Support\PosReports::canSeeProfit(auth()->user());
    $totals = $this->totals;
    $filtersActive = $search !== '' || $filter !== 'all' || $from !== '' || $to !== '' || $salesman !== '' || $method !== 'all';
    $input = 'rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm text-sm';
@endphp

@slot('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Sales</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Counter sales rung up on the POS</p>
        </div>
        <a href="{{ route('tenant.pos.register') }}" wire:navigate
           class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
            <x-tenant-icon name="plus" class="h-4 w-4" /> New Sale
        </a>
    </div>
@endslot

<div class="space-y-4">
    <x-pos-tabs active="sales" />

    <div class="flex flex-wrap items-end gap-3">
        <x-search-input model="search" placeholder="Sale number, customer name or phone…" class="w-full sm:w-80" />

        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" wire:model.live="from" class="{{ $input }}">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" wire:model.live="to" class="{{ $input }}">
        </div>

        <select wire:model.live="salesman" class="{{ $input }}">
            <option value="">All salesmen</option>
            @foreach ($this->salesmen as $user)
                <option value="{{ $user->id }}">{{ $user->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="method" class="{{ $input }}">
            <option value="all">Any payment method</option>
            <option value="cash">Cash</option>
            <option value="bank">Bank</option>
        </select>

        <select wire:model.live="filter" class="{{ $input }}">
            <option value="all">All sales</option>
            <option value="outstanding">Balance due</option>
            <option value="overdue">Overdue</option>
        </select>

        @if ($filtersActive)
            <button type="button" wire:click="clearFilters" class="text-sm font-medium text-walnut-400 hover:text-walnut-200">Clear filters</button>
        @endif
    </div>

    {{-- Totals for everything matching the filters, not just this page --}}
    <div class="grid grid-cols-2 {{ $showProfit ? 'lg:grid-cols-5' : 'lg:grid-cols-4' }} gap-3" wire:loading.class="opacity-50" wire:target="search,filter,from,to,salesman,method">
        <x-stat-card label="Sales" :value="$totals['count']" color="walnut" />
        <x-stat-card label="Net sales" :value="$money($totals['net'])" :hint="bccomp($totals['returned'], '0', 2) > 0 ? $money($totals['returned']).' returned' : ''" color="sky" />
        <x-stat-card label="Collected" :value="$money($totals['collected'])" color="emerald" />
        <x-stat-card label="Balance due" :value="$money($totals['balance_due'])" color="amber" />
        @if ($showProfit)
            <x-stat-card label="Profit" :value="$money($totals['profit'])" color="walnut" />
        @endif
    </div>

    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden"
         wire:loading.class="opacity-50 pointer-events-none" wire:target="search,filter,from,to,salesman,method">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Sale #</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Date</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Customer</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Items</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Paid By</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Total</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Balance</th>
                        @if ($showProfit)
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Profit</th>
                        @endif
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Sold By</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($sales as $sale)
                        <tr wire:key="sale-{{ $sale->id }}">
                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $sale->sale_number }}</td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $sale->sold_at->format('d M Y, h:i A') }}</td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                {{ $sale->customerName() }}
                                @if ($sale->isWalkIn())
                                    <span class="ml-1 text-xs text-gray-400">walk-in</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right text-gray-500 dark:text-gray-400">{{ $sale->items_count }}</td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $sale->paymentSummary() }}</td>
                            <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ $money($sale->total) }}</td>
                            <td class="px-4 py-2.5 text-right {{ $sale->isOverdue() ? 'font-medium text-rose-600' : 'text-gray-700 dark:text-gray-300' }}">
                                {{ $sale->hasBalanceDue() ? $money($sale->balanceDue()) : '—' }}
                                @if ($sale->hasBalanceDue() && $sale->due_date)
                                    <span class="block text-xs font-normal {{ $sale->isOverdue() ? 'text-rose-500' : 'text-gray-400' }}">due {{ $sale->due_date->format('d M') }}</span>
                                @endif
                            </td>
                            @if ($showProfit)
                                @php
                                    $profit = bcsub($sale->netTotal(), number_format((float) $sale->kept_cost, 2, '.', ''), 2);
                                @endphp
                                <td class="px-4 py-2.5 text-right {{ bccomp($profit, '0', 2) < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $money($profit) }}</td>
                            @endif
                            <td class="px-4 py-2.5"><x-status-badge :status="$sale->paymentState()" /></td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $sale->seller?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('tenant.sales.show', $sale) }}" wire:navigate class="text-walnut-400 hover:text-walnut-200 font-medium text-xs">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $showProfit ? 11 : 10 }}" class="px-4 py-8 text-center text-gray-400">
                            {{ $filtersActive ? 'No sales match.' : 'No sales yet — ring one up on the POS.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $sales->links() }}
</div>
