<?php

use App\Models\DayClosing;
use App\Support\PosCalculator;
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
    public string $date = '';

    public string $openingFloat = '';

    public string $countedCash = '';

    public string $notes = '';

    public string $notice = '';

    public function mount(): void
    {
        abort_unless(Tenant::current()?->posEnabled(), 404);

        $this->date = $this->day()->toDateString();
        $this->loadCounted();
    }

    /** Falls back to today for anything that is not a real date. */
    private function day(): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $this->date)->startOfDay();
        } catch (\Throwable) {
            return today();
        }
    }

    /** Changing the day shows that day's saved count, if it has one. */
    public function updatedDate(): void
    {
        $this->date = $this->day()->toDateString();
        $this->notice = '';
        $this->resetErrorBag();
        $this->loadCounted();
        unset($this->summary, $this->saved);
    }

    private function loadCounted(): void
    {
        $saved = DayClosing::whereDate('business_date', $this->day())->first();

        $this->openingFloat = $saved ? (string) $saved->opening_float : '';
        $this->countedCash = $saved ? (string) $saved->counted_cash : '';
        $this->notes = (string) $saved?->notes;
    }

    #[Computed]
    public function summary(): array
    {
        return PosReports::day($this->day());
    }

    #[Computed]
    public function saved(): ?DayClosing
    {
        return DayClosing::with('closer')->whereDate('business_date', $this->day())->first();
    }

    #[Computed]
    public function history()
    {
        return DayClosing::with('closer')->latest('business_date')->limit(14)->get();
    }

    /** Cash that should be in the drawer: the float it started with, plus cash taken, less cash given back. */
    public function expected(): string
    {
        return bcsub(
            bcadd(PosCalculator::number($this->openingFloat), $this->summary['cash_in'], 2),
            $this->summary['cash_refunds'],
            2
        );
    }

    public function save(): void
    {
        $this->validate([
            'openingFloat' => 'nullable|numeric|min:0|max:999999999',
            'countedCash' => 'required|numeric|min:0|max:999999999',
            'notes' => 'nullable|string|max:1000',
        ], [
            'countedCash.required' => 'Enter the cash you counted in the drawer.',
        ]);

        $day = $this->day();
        $summary = $this->summary;
        $expected = $this->expected();
        $counted = PosCalculator::number($this->countedCash);

        $attributes = [
            'opening_float' => PosCalculator::number($this->openingFloat),
            'cash_in' => $summary['cash_in'],
            'cash_refunds' => $summary['cash_refunds'],
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'difference' => bcsub($counted, $expected, 2),
            'summary' => $summary,
            'notes' => trim($this->notes) ?: null,
            'closed_by' => auth()->id(),
            'closed_at' => now(),
        ];

        // Looked up by date rather than by an exact column match: how a date
        // is stored differs between databases, and one day must only ever
        // have one closing.
        $existing = DayClosing::whereDate('business_date', $day)->first();

        if ($existing) {
            $existing->update($attributes);
        } else {
            DayClosing::create($attributes + ['shop_id' => Tenant::id(), 'business_date' => $day->toDateString()]);
        }

        unset($this->saved, $this->history);

        $this->notice = 'Closing for '.$day->format('d M Y').' saved.';
    }
} ?>

@php
    $money = fn ($value) => \App\Support\PosCalculator::money((string) $value);
    $card = 'rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5';
    $input = 'block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm shadow-sm focus:border-walnut-400 focus:ring-walnut-400';
    $summary = $this->summary;
    $expected = $this->expected();
    $counted = \App\Support\PosCalculator::number($countedCash);
    $difference = bcsub($counted, $expected, 2);
    $hasCount = trim($countedCash) !== '';
    $saved = $this->saved;
@endphp

@slot('header')
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Day Closing</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Count the drawer and check it against the day's sales</p>
    </div>
@endslot

<div class="max-w-4xl space-y-6">
    <x-pos-tabs active="closing" />

    @if ($notice !== '')
        <div class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Business day</label>
            <input type="date" wire:model.live="date" max="{{ today()->toDateString() }}"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm text-sm">
        </div>

        @if ($saved)
            <p class="text-sm text-gray-500">
                Closed {{ $saved->closed_at->format('d M, h:i A') }}@if ($saved->closer) by {{ $saved->closer->name }}@endif
            </p>
        @else
            <p class="text-sm text-amber-600">Not closed yet</p>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- What the day looked like --}}
        <div class="{{ $card }} p-5 space-y-5" wire:loading.class="opacity-50" wire:target="date">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Sales</h3>
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Sales made</dt><dd class="text-gray-900 dark:text-white">{{ $summary['sales_count'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Total sold</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $money($summary['sales_total']) }}</dd></div>
                    @if (bccomp($summary['discounts'], '0', 2) > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">Discounts given</dt><dd class="text-gray-700 dark:text-gray-300">{{ $money($summary['discounts']) }}</dd></div>
                    @endif
                    @if (bccomp($summary['new_credit'], '0', 2) > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">Left on credit</dt><dd class="text-amber-600">{{ $money($summary['new_credit']) }}</dd></div>
                    @endif
                    @if ($summary['returns_count'] > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">Returns ({{ $summary['returns_count'] }})</dt><dd class="text-rose-600">−{{ $money($summary['returns_value']) }}</dd></div>
                    @endif
                </dl>
            </div>

            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Money in</h3>
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Cash</dt><dd class="font-medium text-emerald-600">{{ $money($summary['cash_in']) }}</dd></div>
                    @foreach ($summary['bank_in_by_bank'] as $bankName => $amount)
                        <div class="flex justify-between"><dt class="text-gray-500">{{ $bankName }}</dt><dd class="text-sky-600">{{ $money($amount) }}</dd></div>
                    @endforeach
                    @if (bccomp($summary['repayments'], '0', 2) > 0)
                        <p class="text-xs text-gray-400">Includes {{ $money($summary['repayments']) }} taken against earlier sales.</p>
                    @endif
                </dl>
            </div>

            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Money out</h3>
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Cash refunds</dt><dd class="text-rose-600">{{ $money($summary['cash_refunds']) }}</dd></div>
                    @if (bccomp($summary['bank_refunds'], '0', 2) > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">Bank refunds</dt><dd class="text-rose-600">{{ $money($summary['bank_refunds']) }}</dd></div>
                    @endif
                    @if (bccomp($summary['ledger_credits'], '0', 2) > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">Refunds left as ledger credit</dt><dd class="text-gray-700 dark:text-gray-300">{{ $money($summary['ledger_credits']) }}</dd></div>
                    @endif
                </dl>
            </div>
        </div>

        {{-- Count the drawer --}}
        <form wire:submit="save" class="{{ $card }} p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Cash in the drawer</h3>

            <div>
                <x-input-label value="Opening float (cash already in the drawer at the start of the day)" />
                <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="openingFloat" placeholder="0" class="mt-1 {{ $input }}">
                <x-input-error :messages="$errors->get('openingFloat')" class="mt-1" />
            </div>

            <dl class="space-y-1.5 rounded-xl bg-gray-50 dark:bg-gray-900/40 p-4 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">Opening float</dt><dd>{{ $money(\App\Support\PosCalculator::number($openingFloat)) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">+ Cash taken</dt><dd>{{ $money($summary['cash_in']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">− Cash refunds</dt><dd>{{ $money($summary['cash_refunds']) }}</dd></div>
                <div class="flex justify-between border-t border-gray-200 dark:border-gray-700 pt-1.5 font-semibold">
                    <dt class="text-gray-900 dark:text-white">Should be in the drawer</dt><dd class="text-gray-900 dark:text-white">{{ $money($expected) }}</dd>
                </div>
            </dl>

            <div>
                <x-input-label value="Cash counted" />
                <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="countedCash" placeholder="Count it and enter the total" class="mt-1 {{ $input }}">
                <x-input-error :messages="$errors->get('countedCash')" class="mt-1" />
            </div>

            @if ($hasCount)
                <div class="rounded-xl px-4 py-3 text-sm font-medium
                            {{ bccomp($difference, '0', 2) === 0 ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
                                : (bccomp($difference, '0', 2) < 0 ? 'bg-rose-50 text-rose-800 border border-rose-200' : 'bg-amber-50 text-amber-800 border border-amber-200') }}">
                    @if (bccomp($difference, '0', 2) === 0)
                        The drawer matches.
                    @elseif (bccomp($difference, '0', 2) < 0)
                        Short by {{ $money(ltrim($difference, '-')) }}
                    @else
                        Over by {{ $money($difference) }}
                    @endif
                </div>
            @endif

            <div>
                <x-text-input wire:model="notes" placeholder="Note — e.g. what the difference was (optional)" class="block w-full text-sm" />
                <x-input-error :messages="$errors->get('notes')" class="mt-1" />
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="inline-flex items-center justify-center rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-walnut-400 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">{{ $saved ? 'Update Closing' : 'Close the Day' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </form>
    </div>

    <div class="{{ $card }} overflow-hidden">
        <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Recent closings</h3>
        <div class="overflow-x-auto mt-4">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Day</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Expected</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Counted</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Difference</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Closed by</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->history as $closing)
                        <tr wire:key="closing-{{ $closing->id }}">
                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white whitespace-nowrap">{{ $closing->business_date->format('d M Y') }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $money($closing->expected_cash) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $money($closing->counted_cash) }}</td>
                            <td class="px-4 py-2.5 text-right font-medium {{ $closing->isShort() ? 'text-rose-600' : ($closing->isOver() ? 'text-amber-600' : 'text-emerald-600') }}">
                                {{ $closing->isShort() || $closing->isOver() ? $money($closing->difference) : 'Matched' }}
                            </td>
                            <td class="px-4 py-2.5 text-gray-500">{{ $closing->closer?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-gray-500">{{ $closing->notes ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No days closed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
