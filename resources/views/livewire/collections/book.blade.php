<?php

use App\Support\CollectionBook;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    public function with(): array
    {
        $groups = CollectionBook::groupedRows($this->search);

        return [
            'groups' => $groups,
            'customerCount' => $groups->sum(fn (array $group) => $group['customers']->count()),
            'grandTotal' => (string) $groups->reduce(fn ($carry, $group) => bcadd($carry, $group['areaTotal'], 2), '0.00'),
        ];
    }
} ?>

@slot('header')
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Collection Book</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Everyone due this month, plus anyone still behind from before</p>
    </div>
@endslot

<div>
    <div class="mb-4 flex flex-col sm:flex-row sm:items-center gap-3">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Search by name, CNIC, or phone…"
               class="block w-full max-w-md rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">

        <a href="{{ route('tenant.collections.book.print', ['q' => $search]) }}"
           target="_blank"
           class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 sm:ml-auto">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" />
            </svg>
            Print collection sheet
        </a>
    </div>

    @if ($customerCount > 0)
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
            {{ $customerCount }} {{ Str::plural('customer', $customerCount) }} due · Rs. {{ number_format((float) $grandTotal, 2) }} total
        </p>
    @endif

    <div class="space-y-8">
        @forelse ($groups as $group)
            <section>
                <h2 class="mb-3 flex items-baseline justify-between border-b border-gray-200 dark:border-gray-700 pb-2">
                    <span class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $group['area'] }}</span>
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Rs. {{ number_format((float) $group['areaTotal'], 2) }}</span>
                </h2>

                <div class="space-y-4">
                    @foreach ($group['customers'] as $row)
                        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
                            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 bg-gray-50/80 dark:bg-gray-900/40 border-b border-gray-100 dark:border-gray-800">
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row['customer']->first_name }} {{ $row['customer']->last_name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['customer']->phone }}{{ $row['customer']->cnic_number ? ' · '.$row['customer']->cnic_number : '' }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Total due</p>
                                    <p class="font-semibold text-gray-900 dark:text-white">Rs. {{ number_format((float) $row['grandTotal'], 2) }}</p>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                                    <thead class="bg-gray-50/60 dark:bg-gray-900/30">
                                        <tr>
                                            <th class="px-4 py-2 text-left font-medium text-gray-500">Agreement</th>
                                            <th class="px-4 py-2 text-left font-medium text-gray-500">Unit(s)</th>
                                            <th class="px-4 py-2 text-right font-medium text-gray-500">This Month</th>
                                            <th class="px-4 py-2 text-right font-medium text-gray-500">Overdue</th>
                                            <th class="px-4 py-2 text-right font-medium text-gray-500">Total</th>
                                            <th class="px-4 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                        @foreach ($row['agreements'] as $agreementRow)
                                            <tr>
                                                <td class="px-4 py-2 text-gray-700 dark:text-gray-200">{{ $agreementRow['agreement']->agreement_number }}</td>
                                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $agreementRow['units'] ?: '—' }}</td>
                                                <td class="px-4 py-2 text-right text-gray-700 dark:text-gray-200">
                                                    {{ bccomp($agreementRow['thisMonth'], '0', 2) > 0 ? 'Rs. '.number_format((float) $agreementRow['thisMonth'], 2) : '—' }}
                                                </td>
                                                <td class="px-4 py-2 text-right">
                                                    @if (bccomp($agreementRow['overdue'], '0', 2) > 0)
                                                        <span class="font-medium text-rose-600 dark:text-rose-400">Rs. {{ number_format((float) $agreementRow['overdue'], 2) }}</span>
                                                    @else
                                                        <span class="text-gray-400">—</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 text-right font-medium text-gray-900 dark:text-white">
                                                    Rs. {{ number_format((float) $agreementRow['total'], 2) }}
                                                </td>
                                                <td class="px-4 py-2 text-right">
                                                    <a href="{{ route('tenant.collections.desk', ['agreement' => $agreementRow['agreement']->id, 'prefillAmount' => $agreementRow['total']]) }}"
                                                       wire:navigate
                                                       class="inline-flex items-center rounded-lg bg-walnut-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-walnut-400">
                                                        Pay
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 px-4 py-10 text-center text-gray-400">
                Nobody's due this month, and nothing's overdue. 🎉
            </div>
        @endforelse
    </div>
</div>
