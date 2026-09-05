@php
    $customerCount = $groups->sum(fn ($group) => $group['customers']->count());
    $grandTotal = (string) $groups->reduce(fn ($carry, $group) => bcadd($carry, $group['areaTotal'], 2), '0.00');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Collection Sheet — {{ $shop?->name ?? config('app.name') }} — {{ $generatedAt->format('d-M-Y') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4; margin: 12mm; }
        @media print {
            .no-print { display: none !important; }
            .area-section { break-inside: avoid; }
            .customer-block { break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-gray-100 text-black text-[13px] leading-tight">
    <div class="no-print flex justify-center py-4">
        <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
            Print
        </button>
    </div>

    <div class="mx-auto bg-white p-6" style="max-width: 210mm;">
        <div class="text-center pb-3 border-b-2 border-black">
            <p class="text-lg font-bold uppercase">{{ $shop?->name ?? config('app.name') }}</p>
            <p class="text-sm font-semibold">Customers Collection Sheet — {{ $monthLabel }}{{ $isCurrentMonth ? '' : ' (historical snapshot)' }}</p>
            <p class="text-xs text-gray-600">Generated {{ $generatedAt->format('d-M-Y, h:i A') }} · {{ $customerCount }} {{ Str::plural('customer', $customerCount) }} · Rs. {{ number_format((float) $grandTotal, 0) }} due this sheet</p>
        </div>

        <div class="mt-3 grid grid-cols-3 gap-2 text-center border-b border-black pb-3">
            <div>
                <p class="text-[10px] uppercase text-gray-600">Total Amount</p>
                <p class="font-semibold">Rs. {{ number_format((float) $summary['totalAmount'], 0) }}</p>
            </div>
            <div>
                <p class="text-[10px] uppercase text-gray-600">Total Receivable</p>
                <p class="font-semibold">Rs. {{ number_format((float) $summary['totalReceivable'], 0) }}</p>
            </div>
            <div>
                <p class="text-[10px] uppercase text-gray-600">Overdue</p>
                <p class="font-semibold">Rs. {{ number_format((float) $summary['totalOverdue'], 0) }}</p>
            </div>
        </div>

        @forelse ($groups as $group)
            <div class="area-section mt-5">
                <div class="flex items-baseline justify-between border-b border-black pb-1 mb-2">
                    <span class="font-bold uppercase">{{ $group['area'] }}</span>
                    <span class="font-semibold">Rs. {{ number_format((float) $group['areaTotal'], 0) }}</span>
                </div>

                @foreach ($group['customers'] as $row)
                    <div class="customer-block mb-3 border border-black">
                        <div class="flex flex-wrap items-center justify-between gap-2 px-2 py-1 bg-gray-200 border-b border-black">
                            <span class="font-semibold">{{ $row['customer']->first_name }} {{ $row['customer']->last_name }}</span>
                            <span>{{ $row['customer']->phone }}{{ $row['customer']->cnic_number ? ' · '.$row['customer']->cnic_number : '' }}</span>
                            <span class="font-semibold">Total: Rs. {{ number_format((float) $row['grandTotal'], 0) }}</span>
                        </div>

                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="border-b border-black">
                                    <th class="px-2 py-1 text-left font-semibold">Agreement</th>
                                    <th class="px-2 py-1 text-left font-semibold">Unit(s)</th>
                                    <th class="px-2 py-1 text-right font-semibold">This Month</th>
                                    <th class="px-2 py-1 text-right font-semibold">Overdue</th>
                                    <th class="px-2 py-1 text-right font-semibold">Total</th>
                                    <th class="px-2 py-1 text-center font-semibold w-16">Collected</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($row['agreements'] as $agreementRow)
                                    <tr class="border-b border-gray-300 last:border-b-0">
                                        <td class="px-2 py-1">{{ $agreementRow['agreement']->agreement_number }}</td>
                                        <td class="px-2 py-1">{{ $agreementRow['units'] ?: '—' }}</td>
                                        <td class="px-2 py-1 text-right">
                                            {{ bccomp($agreementRow['thisMonth'], '0', 2) > 0 ? number_format((float) $agreementRow['thisMonth'], 0) : '—' }}
                                        </td>
                                        <td class="px-2 py-1 text-right">
                                            {{ bccomp($agreementRow['overdue'], '0', 2) > 0 ? number_format((float) $agreementRow['overdue'], 0) : '—' }}
                                        </td>
                                        <td class="px-2 py-1 text-right font-semibold">{{ number_format((float) $agreementRow['total'], 0) }}</td>
                                        <td class="px-2 py-1 text-center">
                                            <span class="inline-block h-4 w-4 border border-black align-middle"></span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        @empty
            <p class="mt-6 text-center text-gray-500">Nobody's due this month, and nothing's overdue.</p>
        @endforelse
    </div>
</body>
</html>
