<?php

use App\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.customer')] class extends Component
{
    public Sale $sale;

    public function mount(Sale $sale): void
    {
        // The route is scoped to this shop, but another customer's sale of the
        // same shop would still resolve — so ownership is checked explicitly.
        abort_unless($sale->customer_id !== null && $sale->customer_id === Auth::guard('customer')->id(), 403);

        $this->sale = $sale->load(['items', 'payments.bank', 'returns.items.saleItem', 'returns.bank']);
    }
} ?>

@php
    $shop = \App\Support\Tenant::current();
    $money = fn ($value) => \App\Support\PosCalculator::money((string) $value);
    $hasBalance = $sale->hasBalanceDue();
@endphp

@slot('header')
    <a href="{{ route('customer.purchases', ['shop' => $shop]) }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">← All purchases</a>
    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $sale->sale_number }}</h1>
            <p class="text-sm text-gray-500">{{ $sale->sold_at->format('d M Y, h:i A') }}</p>
        </div>
        <x-status-badge :status="$sale->paymentState()" />
    </div>
@endslot

<div class="space-y-5">
    <div class="rounded-2xl border border-black/5 bg-white p-5">
        <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-xs text-gray-500">Total</dt>
                <dd class="mt-0.5 font-medium text-gray-900">{{ $money($sale->total) }}</dd>
            </div>
            @if (bccomp((string) $sale->returned_total, '0', 2) > 0)
                <div>
                    <dt class="text-xs text-gray-500">Returned</dt>
                    <dd class="mt-0.5 font-medium text-gray-900">−{{ $money($sale->returned_total) }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-xs text-gray-500">Paid</dt>
                <dd class="mt-0.5 font-medium text-gray-900">{{ $money($sale->netPaid()) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Balance due</dt>
                <dd class="mt-0.5 font-semibold {{ $sale->isOverdue() ? 'text-rose-600' : 'text-gray-900' }}">{{ $money($sale->balanceDue()) }}</dd>
            </div>
            @if ($hasBalance && $sale->due_date)
                <div>
                    <dt class="text-xs text-gray-500">Due by</dt>
                    <dd class="mt-0.5 font-medium {{ $sale->isOverdue() ? 'text-rose-600' : 'text-gray-900' }}">{{ $sale->due_date->format('d M Y') }}</dd>
                </div>
            @endif
        </dl>

        <a href="{{ route('customer.purchase.receipt', ['shop' => $shop, 'sale' => $sale]) }}" target="_blank"
           class="mt-4 inline-flex rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">
            Sale receipt
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-black/5 bg-white">
        <h2 class="p-4 pb-0 text-sm font-semibold text-gray-900">Items</h2>
        <div class="mt-2 divide-y divide-gray-100">
            @foreach ($sale->displayLines() as $line)
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">
                            {{ $line->name }}
                            @if ($line->returned > 0)
                                <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                                    {{ $line->returned >= $line->quantity ? 'Returned' : $line->returned.' returned' }}
                                </span>
                            @endif
                        </p>
                        <p class="text-xs text-gray-500">
                            {{ $line->quantity }} × {{ $money($line->unit_price) }}
                            @if ($line->serial) · <span class="font-mono">{{ $line->serial }}</span> @endif
                        </p>
                    </div>
                    <p class="shrink-0 text-sm font-medium text-gray-900">{{ $money($line->line_total) }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-black/5 bg-white">
        <h2 class="p-4 pb-0 text-sm font-semibold text-gray-900">Payments</h2>
        <div class="mt-2 divide-y divide-gray-100">
            @forelse ($sale->payments as $payment)
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ $money($payment->amount) }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $payment->paid_at->format('d M Y') }} · {{ $payment->receipt_number }} ·
                            {{ \App\Support\PaymentMethod::label($payment->payment_mode, $payment->bank) }}
                        </p>
                    </div>
                    <a href="{{ route('customer.purchase.payment-receipt', ['shop' => $shop, 'sale' => $sale, 'payment' => $payment]) }}" target="_blank"
                       class="shrink-0 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">
                        Receipt
                    </a>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-gray-400">No payments recorded yet.</p>
            @endforelse
        </div>
    </div>

    @if ($sale->returns->isNotEmpty())
        <div class="overflow-hidden rounded-2xl border border-black/5 bg-white">
            <h2 class="p-4 pb-0 text-sm font-semibold text-gray-900">Returns</h2>
            <div class="mt-2 divide-y divide-gray-100">
                @foreach ($sale->returns as $return)
                    <div class="px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-medium text-gray-900">{{ $return->return_number }} · {{ $return->returned_at->format('d M Y') }}</p>
                            <p class="text-sm font-medium text-gray-900">{{ $money($return->total_value) }}</p>
                        </div>
                        <p class="text-xs text-gray-500">
                            @foreach ($return->items as $returnItem)
                                {{ $returnItem->quantity }} × {{ $returnItem->saleItem?->product_name }}{{ ! $loop->last ? ', ' : '' }}
                            @endforeach
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500">
                            @if (bccomp((string) $return->refund_amount, '0', 2) > 0)
                                {{ $money($return->refund_amount) }} refunded · {{ $return->refundLabel() }}
                            @elseif (bccomp((string) $return->credit_amount, '0', 2) > 0)
                                {{ $money($return->credit_amount) }} credited to your account
                            @else
                                Deducted from what you owed
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
