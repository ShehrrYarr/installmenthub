<?php

use App\Models\Agreement;
use App\Models\CustomerLedgerEntry;
use App\Models\Expense;
use App\Models\InstallmentSchedule;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\VendorLedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    /** One of 30d, 6m, 12m — anything else falls back to 30d. */
    public string $range = '30d';

    public function setRange(string $range): void
    {
        $this->range = $range;

        unset($this->charts, $this->totals);

        $this->dispatch('charts-updated', charts: $this->charts);
    }

    private function groupsByDay(): bool
    {
        return $this->range === '30d';
    }

    /** Bucket keys in chronological order, mapped to the labels shown on the x-axis. */
    private function buckets(): array
    {
        $buckets = [];

        if ($this->groupsByDay()) {
            $cursor = today()->subDays(29);

            for ($i = 0; $i < 30; $i++) {
                $buckets[$cursor->format('Y-m-d')] = $cursor->format('d M');
                $cursor = $cursor->addDay();
            }

            return $buckets;
        }

        $months = $this->range === '12m' ? 12 : 6;
        $cursor = today()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $buckets[$cursor->format('Y-m')] = $cursor->format('M Y');
            $cursor = $cursor->addMonth();
        }

        return $buckets;
    }

    private function rangeStart(): Carbon
    {
        return $this->groupsByDay()
            ? today()->subDays(29)
            : today()->startOfMonth()->subMonths(($this->range === '12m' ? 12 : 6) - 1);
    }

    /**
     * Sums $amountColumn per bucket, returning one number per bucket in
     * chronological order (zero-filled), ready to hand straight to Chart.js.
     */
    private function series(Builder $query, string $dateColumn, string $amountColumn = 'amount'): array
    {
        $format = $this->groupsByDay() ? '%Y-%m-%d' : '%Y-%m';

        $totals = $query
            ->whereDate($dateColumn, '>=', $this->rangeStart())
            ->groupBy('bucket')
            ->selectRaw("DATE_FORMAT({$dateColumn}, '{$format}') as bucket, SUM({$amountColumn}) as total")
            ->pluck('total', 'bucket');

        return collect($this->buckets())
            ->keys()
            ->map(fn ($key) => round((float) ($totals[$key] ?? 0), 2))
            ->all();
    }

    /** Every series the dashboard draws, in the shape dashboard-charts.js expects. */
    #[Computed]
    public function charts(): array
    {
        $labels = array_values($this->buckets());

        $signed = $this->series(Agreement::query(), 'created_at', 'product_price');
        $collected = $this->series(Payment::query(), 'paid_at');
        $purchases = $this->series(PurchaseOrder::query(), 'order_date', 'total_amount');

        // Cash in: customer payments, plus manual "Cash In" rows on a customer
        // ledger (manual entries are the ones with no source document), plus
        // refunds a vendor sent back.
        $manualCustomerIn = $this->series(
            CustomerLedgerEntry::query()->whereNull('reference_type')->where('type', 'credit'),
            'entry_date',
        );
        $vendorRefunds = $this->series(
            VendorLedgerEntry::query()->where('manual_direction', 'cash_in'),
            'entry_date',
        );

        // Cash out: money actually paid to vendors — the automatic
        // "payment on receipt" credits plus manual payments — and expenses.
        // A vendor "debit" is the invoice itself, not cash leaving, so it's out.
        $vendorPayments = $this->series(
            VendorLedgerEntry::query()
                ->where('type', 'credit')
                ->where(fn ($q) => $q->whereNull('manual_direction')->orWhere('manual_direction', 'cash_out')),
            'entry_date',
        );
        $expenses = $this->series(Expense::query(), 'expense_date');

        $sum = fn (array ...$series) => array_map(fn (...$values) => array_sum($values), ...$series);

        return [
            'sales' => [
                'type' => 'line',
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'Agreements Signed', 'data' => $signed, 'color' => '#a16207', 'fill' => 'rgba(161, 98, 7, 0.10)'],
                    ['label' => 'Cash Collected', 'data' => $collected, 'color' => '#059669', 'fill' => 'rgba(5, 150, 105, 0.10)'],
                ],
            ],
            'purchases' => [
                'type' => 'bar',
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'Purchases', 'data' => $purchases, 'color' => '#0284c7', 'fill' => 'rgba(2, 132, 199, 0.65)'],
                ],
            ],
            'cashflow' => [
                'type' => 'line',
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'Cash In', 'data' => $sum($collected, $manualCustomerIn, $vendorRefunds), 'color' => '#059669', 'fill' => 'rgba(5, 150, 105, 0.10)'],
                    ['label' => 'Cash Out', 'data' => $sum($vendorPayments, $expenses), 'color' => '#e11d48', 'fill' => 'rgba(225, 29, 72, 0.10)'],
                ],
            ],
        ];
    }

    /** Range totals shown under each chart's title. */
    #[Computed]
    public function totals(): array
    {
        $charts = $this->charts;

        return [
            'signed' => array_sum($charts['sales']['datasets'][0]['data']),
            'collected' => array_sum($charts['sales']['datasets'][1]['data']),
            'purchases' => array_sum($charts['purchases']['datasets'][0]['data']),
            'cash_in' => array_sum($charts['cashflow']['datasets'][0]['data']),
            'cash_out' => array_sum($charts['cashflow']['datasets'][1]['data']),
        ];
    }

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

    <div class="flex items-center justify-between mb-4">
        <h2 class="font-semibold text-gray-900 dark:text-white">Trends</h2>
        <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 bg-white/70 dark:bg-gray-800/60 p-0.5">
            @foreach (['30d' => '30 days', '6m' => '6 months', '12m' => '12 months'] as $key => $label)
                <button type="button" wire:click="setRange('{{ $key }}')"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition
                           {{ $range === $key
                                ? 'bg-walnut-600 text-white'
                                : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="lg:col-span-2 rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
                <h3 class="font-semibold text-gray-900 dark:text-white">Sales</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Signed <span class="font-semibold text-walnut-600 dark:text-walnut-400">Rs. {{ number_format($this->totals['signed'], 0) }}</span>
                    · Collected <span class="font-semibold text-emerald-600 dark:text-emerald-400">Rs. {{ number_format($this->totals['collected'], 0) }}</span>
                </p>
            </div>
            <div wire:ignore x-data="dashboardChart(@js($this->charts['sales']))"
                 @charts-updated.window="update($event.detail.charts.sales)" class="h-64">
                <canvas x-ref="canvas"></canvas>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
                <h3 class="font-semibold text-gray-900 dark:text-white">Purchases</h3>
                <p class="text-xs font-semibold text-sky-600 dark:text-sky-400">Rs. {{ number_format($this->totals['purchases'], 0) }}</p>
            </div>
            <div wire:ignore x-data="dashboardChart(@js($this->charts['purchases']))"
                 @charts-updated.window="update($event.detail.charts.purchases)" class="h-56">
                <canvas x-ref="canvas"></canvas>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
                <h3 class="font-semibold text-gray-900 dark:text-white">Cash Flow</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    In <span class="font-semibold text-emerald-600 dark:text-emerald-400">Rs. {{ number_format($this->totals['cash_in'], 0) }}</span>
                    · Out <span class="font-semibold text-rose-600 dark:text-rose-400">Rs. {{ number_format($this->totals['cash_out'], 0) }}</span>
                </p>
            </div>
            <div wire:ignore x-data="dashboardChart(@js($this->charts['cashflow']))"
                 @charts-updated.window="update($event.detail.charts.cashflow)" class="h-56">
                <canvas x-ref="canvas"></canvas>
            </div>
        </div>
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
