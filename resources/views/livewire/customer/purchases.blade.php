<?php

use App\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.customer')] class extends Component
{
    #[Computed]
    public function sales()
    {
        // Explicitly this customer's — the shop scope alone would show every
        // customer of the shop their neighbours' purchases.
        return Sale::where('customer_id', Auth::guard('customer')->id())
            ->with(['items', 'payments'])
            ->latest('sold_at')
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function totals(): array
    {
        $balance = '0.00';
        $overdue = '0.00';

        foreach ($this->sales as $sale) {
            $balance = bcadd($balance, $sale->balanceDue(), 2);

            if ($sale->isOverdue()) {
                $overdue = bcadd($overdue, $sale->balanceDue(), 2);
            }
        }

        return ['balance' => $balance, 'overdue' => $overdue];
    }
} ?>

@php
    $shop = \App\Support\Tenant::current();
    $money = fn ($value) => \App\Support\PosCalculator::money((string) $value);
@endphp

@slot('header')
    <h1 class="text-xl font-semibold text-gray-900">My Purchases</h1>
    <p class="text-sm text-gray-500">What you've bought outright from {{ $shop?->name }}</p>
@endslot

<div class="space-y-5">
    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-2xl border p-4 {{ bccomp($this->totals['balance'], '0', 2) > 0 ? 'border-amber-200 bg-amber-50' : 'border-black/5 bg-white' }}">
            <p class="text-xs {{ bccomp($this->totals['balance'], '0', 2) > 0 ? 'text-amber-700' : 'text-gray-500' }}">Balance due</p>
            <p class="mt-1 text-xl font-semibold {{ bccomp($this->totals['balance'], '0', 2) > 0 ? 'text-amber-800' : 'text-gray-900' }}">{{ $money($this->totals['balance']) }}</p>
        </div>
        <div class="rounded-2xl border p-4 {{ bccomp($this->totals['overdue'], '0', 2) > 0 ? 'border-rose-200 bg-rose-50' : 'border-black/5 bg-white' }}">
            <p class="text-xs {{ bccomp($this->totals['overdue'], '0', 2) > 0 ? 'text-rose-600' : 'text-gray-500' }}">Overdue</p>
            <p class="mt-1 text-xl font-semibold {{ bccomp($this->totals['overdue'], '0', 2) > 0 ? 'text-rose-700' : 'text-gray-900' }}">{{ $money($this->totals['overdue']) }}</p>
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($this->sales as $sale)
            <a href="{{ route('customer.purchase', ['shop' => $shop, 'sale' => $sale]) }}" wire:navigate
               class="group block rounded-2xl border border-black/5 bg-white p-4 shadow-sm transition
                      hover:-translate-y-0.5 hover:border-[var(--theme-accent)] hover:shadow-lg
                      focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--theme-accent)]">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 transition-colors group-hover:text-[var(--theme-accent)]">
                            {{ $sale->displayLines()->pluck('name')->take(2)->implode(', ') }}{{ $sale->displayLines()->count() > 2 ? ' +'.($sale->displayLines()->count() - 2).' more' : '' }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $sale->sale_number }} · {{ $sale->sold_at->format('d M Y') }}</p>
                    </div>
                    <x-status-badge :status="$sale->paymentState()" />
                </div>

                <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">Total</dt>
                        <dd class="font-medium text-gray-900">{{ $money($sale->total) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Paid</dt>
                        <dd class="font-medium text-gray-900">{{ $money($sale->netPaid()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Balance</dt>
                        <dd class="font-medium {{ $sale->isOverdue() ? 'text-rose-600' : 'text-gray-900' }}">{{ $money($sale->balanceDue()) }}</dd>
                    </div>
                </dl>

                @if ($sale->hasBalanceDue() && $sale->due_date)
                    <p class="mt-2 text-xs {{ $sale->isOverdue() ? 'text-rose-600' : 'text-gray-500' }}">
                        Due {{ $sale->due_date->format('d M Y') }}{{ $sale->isOverdue() ? ' — overdue' : '' }}
                    </p>
                @endif
            </a>
        @empty
            <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400">
                You haven't bought anything over the counter yet.
            </div>
        @endforelse
    </div>
</div>
