@php
    $shop = $payment->agreement->shop;
    $customer = $payment->customer;
    $agreement = $payment->agreement;
    $outstanding = $agreement->outstandingBalance();

    $digits = preg_replace('/\D/', '', $customer->phone);
    $waNumber = str_starts_with($digits, '0') ? '92'.substr($digits, 1) : $digits;

    $waText = "Receipt {$payment->receipt_number}\n"
        .($shop->name ?? config('app.name'))."\n"
        ."Amount Received: Rs. ".number_format((float) $payment->amount, 0)."\n"
        ."Agreement: {$agreement->agreement_number}\n"
        ."Outstanding Balance: Rs. ".number_format((float) $outstanding, 0)."\n"
        ."Paid on: {$payment->paid_at->format('d M Y, h:i A')}\n"
        ."Thank you for your payment.";
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $payment->receipt_number }}</title>
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
        <a href="https://wa.me/{{ $waNumber }}?text={{ rawurlencode($waText) }}" target="_blank"
           class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">
            Send via WhatsApp
        </a>
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
            <div class="flex justify-between"><span>Receipt #</span><span>{{ $payment->receipt_number }}</span></div>
            <div class="flex justify-between"><span>Date</span><span>{{ $payment->paid_at->format('d-M-Y H:i') }}</span></div>
            <div class="flex justify-between"><span>Agreement</span><span>{{ $agreement->agreement_number }}</span></div>
            <div class="flex justify-between"><span>Cashier</span><span>{{ $payment->receiver?->name ?? '—' }}</span></div>
        </div>

        <div class="py-2 border-b border-dashed border-black space-y-0.5">
            <p class="font-bold">CUSTOMER</p>
            <div class="flex justify-between"><span>Name</span><span>{{ $customer->first_name }} {{ $customer->last_name }}</span></div>
            <div class="flex justify-between"><span>Phone</span><span>{{ $customer->phone }}</span></div>
        </div>

        <div class="py-2 border-b border-dashed border-black space-y-1">
            <div class="flex justify-between"><span>Payment Mode</span><span class="uppercase">{{ \App\Support\PaymentMethod::label($payment->payment_mode, $payment->bank) }}</span></div>
            @if ($payment->reference_number)
                <div class="flex justify-between"><span>Reference</span><span>{{ $payment->reference_number }}</span></div>
            @endif
            <div class="flex justify-between text-sm font-bold pt-1">
                <span>AMOUNT PAID</span><span>Rs. {{ number_format((float) $payment->amount, 0) }}</span>
            </div>
        </div>

        <div class="py-2 space-y-0.5">
            <div class="flex justify-between"><span>Outstanding Balance</span><span>Rs. {{ number_format((float) $outstanding, 0) }}</span></div>
            <div class="flex justify-between"><span>Next Due</span>
                <span>
                    @php($next = $agreement->schedules()->whereIn('status', ['pending', 'partial', 'overdue'])->orderBy('installment_number')->first())
                    {{ $next?->due_date->format('d-M-Y') ?? '—' }}
                </span>
            </div>
        </div>

        <div class="pt-2 border-t border-dashed border-black text-center">
            <p>Thank you for your payment.</p>
            <p class="text-[10px] text-gray-500">Powered by InstallmentHub</p>
        </div>
    </div>
</body>
</html>
