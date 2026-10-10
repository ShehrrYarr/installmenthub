@php
    $money = fn ($value) => \App\Support\PosCalculator::money((string) $value);
    $lines = $sale->displayLines();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $sale->sale_number }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: 80mm auto; margin: 0; }
        @media print {
            body { width: 80mm; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-gray-100 font-mono text-[12px] leading-tight">
    <div class="no-print flex justify-center gap-3 py-4">
        <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
            Print Receipt
        </button>
        @unless ($portal ?? false)
            <a href="{{ $sale->whatsappUrl() }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">
                Send via WhatsApp
            </a>
        @endunless
    </div>

    <div class="mx-auto bg-white text-black p-3" style="width: 80mm;">
        <div class="text-center space-y-0.5 pb-2 border-b border-dashed border-black">
            <p class="text-sm font-bold uppercase">{{ $shop->name ?? config('app.name') }}</p>
            @if ($shop->address ?? null)
                <p>{{ $shop->address }}</p>
            @endif
            @if ($shop->phone ?? null)
                <p>Tel: {{ $shop->phone }}</p>
            @endif
        </div>

        <div class="py-2 border-b border-dashed border-black space-y-0.5">
            <div class="flex justify-between"><span>Receipt #</span><span>{{ $sale->sale_number }}</span></div>
            <div class="flex justify-between"><span>Date</span><span>{{ $sale->sold_at->format('d-M-Y H:i') }}</span></div>
            <div class="flex justify-between"><span>Cashier</span><span>{{ $sale->seller?->name ?? '—' }}</span></div>
            <div class="flex justify-between"><span>Customer</span><span>{{ $sale->customerName() }}</span></div>
        </div>

        <div class="py-2 border-b border-dashed border-black space-y-1.5">
            @foreach ($lines as $line)
                <div>
                    <p class="font-bold">{{ $line->name }}</p>
                    @if ($line->serial)
                        <p class="text-[10px]">S/N: {{ $line->serial }}</p>
                    @endif
                    <div class="flex justify-between">
                        <span>{{ $line->quantity }} x {{ $money($line->unit_price) }}</span>
                        <span>{{ $money(bcmul($line->unit_price, (string) $line->quantity, 2)) }}</span>
                    </div>
                    @if (bccomp($line->discount, '0', 2) > 0)
                        <div class="flex justify-between"><span>Discount</span><span>−{{ $money($line->discount) }}</span></div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="py-2 border-b border-dashed border-black space-y-0.5">
            @if (bccomp((string) $sale->bill_discount_amount, '0', 2) > 0)
                <div class="flex justify-between"><span>Subtotal</span><span>{{ $money(bcsub((string) $sale->subtotal, (string) $sale->line_discount_total, 2)) }}</span></div>
                <div class="flex justify-between"><span>Bill Discount</span><span>−{{ $money($sale->bill_discount_amount) }}</span></div>
            @endif
            <div class="flex justify-between text-sm font-bold pt-1">
                <span>TOTAL</span><span>{{ $money($sale->total) }}</span>
            </div>
        </div>

        <div class="py-2 space-y-0.5">
            @foreach ($sale->payments as $payment)
                <div class="flex justify-between">
                    <span class="uppercase">{{ \App\Support\PaymentMethod::label($payment->payment_mode, $payment->bank) }}</span>
                    <span>{{ $money($payment->amount) }}</span>
                </div>
                @if ($payment->reference_number)
                    <div class="flex justify-between text-[10px]"><span>Ref</span><span>{{ $payment->reference_number }}</span></div>
                @endif
                @if ($payment->tendered !== null)
                    <div class="flex justify-between text-[10px]"><span>Tendered</span><span>{{ $money($payment->tendered) }}</span></div>
                    @if ($payment->changeGiven())
                        <div class="flex justify-between text-[10px]"><span>Change</span><span>{{ $money($payment->changeGiven()) }}</span></div>
                    @endif
                @endif
            @endforeach
        </div>

        @if (bccomp((string) $sale->returned_total, '0', 2) > 0 || $sale->hasBalanceDue())
            <div class="py-2 border-t border-dashed border-black space-y-0.5">
                @if (bccomp((string) $sale->returned_total, '0', 2) > 0)
                    <div class="flex justify-between"><span>Returned</span><span>−{{ $money($sale->returned_total) }}</span></div>
                @endif
                @if ($sale->hasBalanceDue())
                    <div class="flex justify-between text-sm font-bold"><span>BALANCE DUE</span><span>{{ $money($sale->balanceDue()) }}</span></div>
                    @if ($sale->due_date)
                        <div class="flex justify-between"><span>Due by</span><span>{{ $sale->due_date->format('d-M-Y') }}</span></div>
                    @endif
                @endif
            </div>
        @endif

        <div class="pt-2 border-t border-dashed border-black text-center">
            <p>Thank you for your purchase.</p>
            <p class="text-[10px] text-gray-500">Powered by InstallmentHub</p>
        </div>
    </div>
</body>
</html>
