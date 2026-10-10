<?php

use App\Models\Sale;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public Sale $sale;

    public function mount(Sale $sale): void
    {
        abort_unless(Tenant::current()?->posEnabled(), 404);

        $this->sale = $sale->load(['shop', 'customer', 'seller', 'items', 'payments.bank', 'payments.media']);
    }
} ?>

@php
    $money = fn ($value) => \App\Support\PosCalculator::money((string) $value);
    $card = 'rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5';
@endphp

@slot('header')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $sale->sale_number }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $sale->sold_at->format('d M Y, h:i A') }}@if ($sale->seller) · sold by {{ $sale->seller->name }}@endif</p>
        </div>
        <x-status-badge :status="$sale->status" />
    </div>
@endslot

<div class="max-w-4xl space-y-6">
    @if (session('status'))
        <div class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        <a href="{{ route('tenant.sales.receipt', $sale) }}" target="_blank"
           class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
            <x-tenant-icon name="printer" class="h-4 w-4" /> Print Receipt (80mm)
        </a>
        <a href="{{ route('tenant.sales.invoice', $sale) }}" target="_blank"
           class="inline-flex items-center gap-2 rounded-lg bg-white/70 border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white">
            <x-tenant-icon name="document-text" class="h-4 w-4" /> A4 Invoice
        </a>
        <a href="{{ $sale->whatsappUrl() }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">
            <x-tenant-icon name="phone" class="h-4 w-4" /> Send via WhatsApp
        </a>
        <a href="{{ route('tenant.pos.register') }}" wire:navigate
           class="inline-flex items-center gap-2 rounded-lg bg-white/70 border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white">
            <x-tenant-icon name="plus" class="h-4 w-4" /> New Sale
        </a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div class="{{ $card }} p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Customer</h3>
            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $sale->customerName() }}</p>
            @if ($sale->customerPhone())
                <p class="text-sm text-gray-500">{{ $sale->customerPhone() }}</p>
            @endif
            @if ($sale->customer)
                <a href="{{ route('tenant.customers.ledger', $sale->customer) }}" wire:navigate class="mt-2 inline-block text-sm text-walnut-400 hover:text-walnut-200">View ledger</a>
            @else
                <p class="mt-1 text-xs text-gray-400">Walk-in sale — not linked to a customer record.</p>
            @endif
        </div>

        <div class="{{ $card }} p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Totals</h3>
            <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd>{{ $money($sale->subtotal) }}</dd></div>
                @if (bccomp((string) $sale->line_discount_total, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Item discounts</dt><dd class="text-rose-600">−{{ $money($sale->line_discount_total) }}</dd></div>
                @endif
                @if (bccomp((string) $sale->bill_discount_amount, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Bill discount</dt><dd class="text-rose-600">−{{ $money($sale->bill_discount_amount) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-gray-100 dark:border-gray-700 pt-1.5 font-semibold"><dt>Total</dt><dd>{{ $money($sale->total) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Paid</dt><dd class="text-emerald-600">{{ $money($sale->amount_paid) }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="{{ $card }} overflow-hidden">
        <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Items</h3>
        <div class="overflow-x-auto mt-4">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Item</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Serial / IMEI</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Qty</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Price</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Discount</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($sale->displayLines() as $line)
                        <tr>
                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $line->name }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-500">{{ $line->serial ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $line->quantity }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $money($line->unit_price) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ bccomp($line->discount, '0', 2) > 0 ? $money($line->discount) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ $money($line->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="{{ $card }} overflow-hidden">
        <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Payments</h3>
        <div class="overflow-x-auto mt-4">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Method</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Reference</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Amount</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Change</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($sale->payments as $payment)
                        <tr>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                {{ \App\Support\PaymentMethod::label($payment->payment_mode, $payment->bank) }}
                                <x-receipt-proof-link :proof="$payment->receiptProof()" :missing="$payment->missingReceiptProof()" class="ml-1" />
                            </td>
                            <td class="px-4 py-2.5 text-gray-500">{{ $payment->reference_number ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ $money($payment->amount) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ $payment->changeGiven() ? $money($payment->changeGiven()) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($sale->notes)
        <div class="{{ $card }} p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Note</h3>
            <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{{ $sale->notes }}</p>
        </div>
    @endif
</div>
