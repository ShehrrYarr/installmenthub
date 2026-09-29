@php
    $customer = $agreement->customer;
    $fullName = trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $agreement->agreement_number }}</title>
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
                    <p class="text-gray-500">{{ now()->format('d M Y') }}</p>
                </div>
            </div>
        @else
            <div class="flex items-start justify-between mb-6">
                <h2 class="text-lg font-bold uppercase tracking-wide text-gray-900">Invoice</h2>
                <p class="text-gray-500">{{ now()->format('d M Y') }}</p>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-6 mb-6">
            @if ($shop->invoice_show_agreement_info)
                <div>
                    <h3 class="font-semibold text-gray-900 mb-1.5">Agreement</h3>
                    <dl class="space-y-1">
                        <div class="flex justify-between"><dt class="text-gray-500">Agreement #</dt><dd class="font-medium">{{ $agreement->agreement_number }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Status</dt><dd class="font-medium capitalize">{{ str_replace('_', ' ', $agreement->status) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Start Date</dt><dd class="font-medium">{{ $agreement->start_date?->format('d M Y') ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">First Installment</dt><dd class="font-medium">{{ $agreement->first_due_date?->format('d M Y') ?? '—' }}</dd></div>
                        @if ($agreement->approver)
                            <div class="flex justify-between"><dt class="text-gray-500">Approved By</dt><dd class="font-medium">{{ $agreement->approver->name }}</dd></div>
                        @endif
                    </dl>
                </div>
            @endif

            @if ($shop->invoice_show_customer_info)
                <div>
                    <h3 class="font-semibold text-gray-900 mb-1.5">Customer</h3>
                    <dl class="space-y-1">
                        <div class="flex justify-between"><dt class="text-gray-500">Name</dt><dd class="font-medium">{{ $fullName }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">CNIC</dt><dd class="font-medium">{{ $customer->cnic_number }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd class="font-medium">{{ $customer->phone }}</dd></div>
                        @if ($customer->address)
                            <div class="flex justify-between"><dt class="text-gray-500">Address</dt><dd class="font-medium text-right">{{ $customer->address }}{{ $customer->city ? ', '.$customer->city : '' }}</dd></div>
                        @endif
                    </dl>
                </div>
            @endif
        </div>

        @if ($shop->invoice_show_product_items)
            <div class="mb-6">
                <h3 class="font-semibold text-gray-900 mb-1.5">Product(s)</h3>
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-gray-300">
                            <th class="py-1.5 font-medium text-gray-500">Item</th>
                            <th class="py-1.5 font-medium text-gray-500">Serial #</th>
                            <th class="py-1.5 font-medium text-gray-500 text-right">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($agreement->items as $item)
                            <tr class="border-b border-gray-100">
                                <td class="py-1.5">{{ $item->product->name }}</td>
                                <td class="py-1.5 font-mono text-xs text-gray-500">{{ $item->productSerial->serial_number ?? '—' }}</td>
                                <td class="py-1.5 text-right">Rs. {{ number_format((float) $item->unit_price, 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($shop->invoice_show_financial_summary)
            <div class="mb-6">
                <h3 class="font-semibold text-gray-900 mb-1.5">Financial Summary</h3>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-1">
                    <div class="flex justify-between"><dt class="text-gray-500">Product Price</dt><dd class="font-medium">Rs. {{ number_format((float) $agreement->product_price, 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Down Payment</dt><dd class="font-medium">Rs. {{ number_format((float) $agreement->down_payment, 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Processing Fee</dt><dd class="font-medium">Rs. {{ number_format((float) $agreement->processing_fee, 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Interest Rate</dt><dd class="font-medium">{{ $agreement->interest_rate }}%</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Duration</dt><dd class="font-medium">{{ $agreement->duration_months }} mo</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Financed Amount</dt><dd class="font-medium">Rs. {{ number_format((float) $agreement->financed_amount, 0) }}</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-1 mt-1"><dt class="text-gray-700 font-medium">Total Payable</dt><dd class="font-semibold">Rs. {{ number_format((float) $agreement->total_payable, 0) }}</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-1 mt-1"><dt class="text-gray-700 font-medium">Monthly Installment</dt><dd class="font-semibold">Rs. {{ number_format((float) $agreement->monthly_installment, 0) }}</dd></div>
                </dl>
            </div>
        @endif

        @if ($shop->invoice_show_guarantors && $agreement->guarantors->isNotEmpty())
            <div class="mb-6">
                <h3 class="font-semibold text-gray-900 mb-1.5">Guarantors</h3>
                <div class="grid grid-cols-2 gap-6">
                    @foreach ($agreement->guarantors as $guarantor)
                        <div>
                            <p class="font-medium">{{ $guarantor->name }}</p>
                            <p class="text-gray-500">{{ $guarantor->relation }} &middot; {{ $guarantor->mobile_number }}</p>
                            <p class="text-gray-500">{{ $guarantor->cnic_number }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($shop->invoice_show_salesman && $agreement->salesman)
            <div class="mb-6">
                <span class="text-gray-500">Salesman:</span> <span class="font-medium">{{ $agreement->salesman->name }}</span>
            </div>
        @endif

        @if ($shop->invoice_show_emi_schedule)
            <div class="mb-6">
                <h3 class="font-semibold text-gray-900 mb-1.5">Installment Schedule</h3>
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-gray-300">
                            <th class="py-1.5 font-medium text-gray-500">#</th>
                            <th class="py-1.5 font-medium text-gray-500">Due Date</th>
                            <th class="py-1.5 font-medium text-gray-500 text-right">Principal</th>
                            <th class="py-1.5 font-medium text-gray-500 text-right">Interest</th>
                            <th class="py-1.5 font-medium text-gray-500 text-right">Total Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($agreement->schedules as $schedule)
                            <tr class="border-b border-gray-100">
                                <td class="py-1.5">{{ $schedule->installment_number }}</td>
                                <td class="py-1.5">{{ $schedule->due_date->format('d M Y') }}</td>
                                <td class="py-1.5 text-right">Rs. {{ number_format((float) $schedule->principal_component, 0) }}</td>
                                <td class="py-1.5 text-right">Rs. {{ number_format((float) $schedule->interest_component, 0) }}</td>
                                <td class="py-1.5 text-right font-medium">Rs. {{ number_format((float) $schedule->total_due, 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($shop->invoice_show_notes && $agreement->notes)
            <div class="mb-6">
                <h3 class="font-semibold text-gray-900 mb-1.5">Notes</h3>
                <p class="text-gray-700 whitespace-pre-line">{{ $agreement->notes }}</p>
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
