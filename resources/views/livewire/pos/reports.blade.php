<?php

use App\Models\Sale;
use App\Support\PosReports;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        abort_unless(Tenant::current()?->posEnabled(), 404);
        // Cost price, and so profit, is not for every pair of eyes.
        abort_unless(PosReports::canSeeProfit(auth()->user()), 403);

        if ($this->from === '' || $this->to === '') {
            $this->preset('month');
        }
    }

    public function preset(string $name): void
    {
        [$from, $to] = match ($name) {
            'today' => [today(), today()],
            'week' => [today()->startOfWeek(), today()],
            '30d' => [today()->subDays(29), today()],
            default => [today()->startOfMonth(), today()],
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(): array
    {
        $from = $this->parse($this->from) ?? today()->startOfMonth();
        $to = $this->parse($this->to) ?? today();

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    private function parse(string $value): ?Carbon
    {
        try {
            return $value === '' ? null : Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    #[Computed]
    public function totals(): array
    {
        [$from, $to] = $this->period();

        return PosReports::totals(
            Sale::query()->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
        );
    }

    #[Computed]
    public function products()
    {
        return PosReports::byProduct(...$this->period());
    }

    #[Computed]
    public function salesmen()
    {
        return PosReports::bySalesman(...$this->period());
    }
} ?>

@php
    $money = fn ($value) => \App\Support\PosCalculator::money((string) $value);
    $card = 'rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5';
    $input = 'rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm text-sm';
    $totals = $this->totals;
    $margin = fn (string $profit, string $revenue) => bccomp($revenue, '0', 2) > 0
        ? number_format(((float) $profit / (float) $revenue) * 100, 1).'%'
        : '—';
@endphp

@slot('header')
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Sales Reports</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">What the counter sold and earned — after returns</p>
    </div>
@endslot

<div class="space-y-6">
    <x-pos-tabs active="reports" />

    <div class="flex flex-wrap items-end gap-3">
        <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 bg-white/70 dark:bg-gray-800/60 p-0.5">
            @foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', '30d' => 'Last 30 days'] as $key => $label)
                <button type="button" wire:click="preset('{{ $key }}')"
                    class="rounded-md px-3 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" wire:model.live="from" class="{{ $input }}">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" wire:model.live="to" class="{{ $input }}">
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3" wire:loading.class="opacity-50" wire:target="from,to,preset">
        <x-stat-card label="Sales" :value="$totals['count']" color="walnut" />
        <x-stat-card label="Net sales" :value="$money($totals['net'])" :hint="bccomp($totals['returned'], '0', 2) > 0 ? $money($totals['returned']).' returned' : null" color="sky" />
        <x-stat-card label="Collected" :value="$money($totals['collected'])" color="emerald" />
        <x-stat-card label="Balance due" :value="$money($totals['balance_due'])" color="amber" />
        <x-stat-card label="Profit" :value="$money($totals['profit'])" :hint="'Margin '.$margin($totals['profit'], $totals['net'])" color="walnut" />
    </div>

    <div class="{{ $card }} overflow-hidden" wire:loading.class="opacity-50" wire:target="from,to,preset">
        <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Sales by product</h3>
        <div class="overflow-x-auto mt-4">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Product</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Units sold</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Returned</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Net units</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Revenue</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Cost</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Profit</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Margin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->products as $row)
                        <tr wire:key="product-{{ $row->product_id }}">
                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $row->name }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $row->sold }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ $row->returned ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $row->net_units }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-900 dark:text-white">{{ $money($row->revenue) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ $money($row->cost) }}</td>
                            <td class="px-4 py-2.5 text-right font-medium {{ bccomp($row->profit, '0', 2) < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $money($row->profit) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ $margin($row->profit, $row->revenue) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No sales in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="{{ $card }} overflow-hidden" wire:loading.class="opacity-50" wire:target="from,to,preset">
        <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Sales by salesman</h3>
        <div class="overflow-x-auto mt-4">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Salesman</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Sales</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Total sold</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Returned</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Net sales</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Profit</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Margin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->salesmen as $row)
                        <tr wire:key="salesman-{{ $row->user_id ?? 'none' }}">
                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $row->name }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $row->count }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $money($row->gross) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ bccomp($row->returned, '0', 2) > 0 ? $money($row->returned) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-900 dark:text-white">{{ $money($row->net) }}</td>
                            <td class="px-4 py-2.5 text-right font-medium {{ bccomp($row->profit, '0', 2) < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $money($row->profit) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ $margin($row->profit, $row->net) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No sales in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
