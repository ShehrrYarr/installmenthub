@php
    $money = fn ($value) => \App\Support\PosCalculator::money((string) $value);
    $lines = $sale->displayLines();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $sale->sale_number }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4; margin: 15mm; }
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-gray-100 text-[13px] leading-normal text-gray-800">
    <div class="no-print flex justify-center py-4">
        <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
            Print Invoice
        </button>
    </div>

    <div class="mx-auto max-w-[210mm] bg-white p-[15mm] print:p-0 print:max-w-none">
        {{-- Letterhead and terms follow the same switches as the agreement
             invoice (Settings → Invoice), so a shop's paperwork stays consistent. --}}
        @if ($shop->invoice_show_letterhead)
            <div class="flex items-start justify-between border-b border-gray-300 pb-4 mb-6">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">{{ $shop->name }}</h1>
                    @if ($shop->address)
                        <p class="text-gray-500">{{ $shop->address }}{{ $shop->city ? ', '.$shop->city : '' }}</p>
                    @endif
                    <p class="text-gray-500">
                        @if ($shop->phone) {{ $shop->phone }} @endif
                        @if ($shop->phone && $shop->email) &middot; @endif
                        @if ($shop->email) {{ $shop->email }} @endif
                    </p>
                </div>
                <div class="text-right">
                    <h2 class="text-lg font-bold uppercase tracking-wide text-gray-900">Invoice</h2>
                    <p class="text-gray-500">{{ $sale->sale_number }}</p>
                    <p class="text-gray-500">{{ $sale->sold_at->format('d M Y') }}</p>
                </div>
            </div>
        @else
            <div class="flex items-start justify-between mb-6">
                <h2 class="text-lg font-bold uppercase tracking-wide text-gray-900">Invoice</h2>
                <div class="text-right">
                    <p class="text-gray-500">{{ $sale->sale_number }}</p>
                    <p class="text-gray-500">{{ $sale->sold_at->format('d M Y') }}</p>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div>
                <h3 class="font-semibold text-gray-900 mb-1.5">Billed To</h3>
                <dl class="space-y-1">
                    <div class="flex justify-between"><dt class="text-gray-500">Name</dt><dd class="font-medium">{{ $sale->customerName() }}</dd></div>
                    @if ($sale->customerPhone())
                        <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd class="font-medium">{{ $sale->customerPhone() }}</dd></div>
                    @endif
                    @if ($sale->customer?->address)
                        <div class="flex justify-between"><dt class="text-gray-500">Address</dt><dd class="font-medium text-right">{{ $sale->customer->address }}{{ $sale->customer->city ? ', '.$sale->customer->city : '' }}</dd></div>
                    @endif
                </dl>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900 mb-1.5">Sale</h3>
                <dl class="space-y-1">
                    <div class="flex justify-between"><dt class="text-gray-500">Invoice #</dt><dd class="font-medium">{{ $sale->sale_number }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Date</dt><dd class="font-medium">{{ $sale->sold_at->format('d M Y, h:i A') }}</dd></div>
                    @if ($sale->seller)
                        <div class="flex justify-between"><dt class="text-gray-500">Sold By</dt><dd class="font-medium">{{ $sale->seller->name }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-gray-500">Paid By</dt><dd class="font-medium">{{ $sale->paymentSummary() }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="mb-6">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-gray-300">
                        <th class="py-1.5 font-medium text-gray-500">Item</th>
                        <th class="py-1.5 font-medium text-gray-500">Serial #</th>
                        <th class="py-1.5 font-medium text-gray-500 text-right">Qty</th>
                        <th class="py-1.5 font-medium text-gray-500 text-right">Price</th>
                        <th class="py-1.5 font-medium text-gray-500 text-right">Discount</th>
                        <th class="py-1.5 font-medium text-gray-500 text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr class="border-b border-gray-100">
                            <td class="py-1.5">{{ $line->name }}</td>
                            <td class="py-1.5 font-mono text-xs text-gray-500">{{ $line->serial ?? '—' }}</td>
                            <td class="py-1.5 text-right">{{ $line->quantity }}</td>
                            <td class="py-1.5 text-right">{{ $money($line->unit_price) }}</td>
                            <td class="py-1.5 text-right text-gray-500">{{ bccomp($line->discount, '0', 2) > 0 ? $money($line->discount) : '—' }}</td>
                            <td class="py-1.5 text-right font-medium">{{ $money($line->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end mb-6">
            <dl class="w-72 space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd>{{ $money($sale->subtotal) }}</dd></div>
                @if (bccomp((string) $sale->line_discount_total, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Item discounts</dt><dd>−{{ $money($sale->line_discount_total) }}</dd></div>
                @endif
                @if (bccomp((string) $sale->bill_discount_amount, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Bill discount</dt><dd>−{{ $money($sale->bill_discount_amount) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-gray-300 pt-1 mt-1 text-base font-semibold"><dt>Total</dt><dd>{{ $money($sale->total) }}</dd></div>
                @if (bccomp((string) $sale->returned_total, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Returned</dt><dd>−{{ $money($sale->returned_total) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-gray-500">Paid</dt><dd>{{ $money($sale->amount_paid) }}</dd></div>
                @if (bccomp((string) $sale->refunded_total, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Refunded</dt><dd>−{{ $money($sale->refunded_total) }}</dd></div>
                @endif
                @if ($sale->hasBalanceDue())
                    <div class="flex justify-between border-t border-gray-300 pt-1 mt-1 font-semibold"><dt>Balance due</dt><dd>{{ $money($sale->balanceDue()) }}</dd></div>
                    @if ($sale->due_date)
                        <div class="flex justify-between text-gray-500"><dt>Due by</dt><dd>{{ $sale->due_date->format('d M Y') }}</dd></div>
                    @endif
                @endif
            </dl>
        </div>

        <div class="mb-6">
            <h3 class="font-semibold text-gray-900 mb-1.5">Payments</h3>
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-gray-300">
                        <th class="py-1.5 font-medium text-gray-500">Method</th>
                        <th class="py-1.5 font-medium text-gray-500">Reference</th>
                        <th class="py-1.5 font-medium text-gray-500 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sale->payments as $payment)
                        <tr class="border-b border-gray-100">
                            <td class="py-1.5">{{ \App\Support\PaymentMethod::label($payment->payment_mode, $payment->bank) }}</td>
                            <td class="py-1.5 text-gray-500">{{ $payment->reference_number ?: '—' }}</td>
                            <td class="py-1.5 text-right">{{ $money($payment->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($sale->notes)
            <div class="mb-6">
                <h3 class="font-semibold text-gray-900 mb-1.5">Notes</h3>
                <p class="text-gray-700 whitespace-pre-line">{{ $sale->notes }}</p>
            </div>
        @endif

        @if ($shop->invoice_show_terms && $shop->invoice_terms_text)
            <div class="border-t border-gray-300 pt-4 mt-6">
                <h3 class="font-semibold text-gray-900 mb-1.5">Terms &amp; Conditions</h3>
                <p class="text-xs text-gray-500 whitespace-pre-line">{{ $shop->invoice_terms_text }}</p>
            </div>
        @endif
    </div>
</body>
</html>
