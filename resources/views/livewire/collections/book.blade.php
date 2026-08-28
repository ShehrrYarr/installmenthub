<?php

use App\Models\Customer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Build one row per customer: every active agreement of theirs that
     * still has money outstanding, split into "this month" (due within the
     * current calendar month, not yet overdue) and "overdue" (carried over
     * from a prior month — status already flipped to 'overdue', matching
     * the same flag the dashboard and penalty sweep use).
     */
    public function with(): array
    {
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $dueThisPeriod = function ($query) use ($startOfMonth, $endOfMonth) {
            $query->where(fn ($q) => $q->where('status', 'overdue')
                ->orWhere(fn ($q2) => $q2->whereIn('status', ['pending', 'partial'])
                    ->whereBetween('due_date', [$startOfMonth, $endOfMonth])));
        };

        $customers = Customer::query()
            ->where('is_active', true)
            ->whereHas('agreements', fn ($q) => $q->where('status', 'active')
                ->whereHas('schedules', $dueThisPeriod))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$this->search}%")
                ->orWhere('last_name', 'like', "%{$this->search}%")
                ->orWhere('cnic_number', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")))
            ->with(['agreements' => fn ($q) => $q->where('status', 'active')
                ->with(['schedules' => $dueThisPeriod])])
            ->orderBy('first_name')
            ->paginate(20);

        $rows = $customers->through(function (Customer $customer) {
            $agreementRows = $customer->agreements
                ->map(function ($agreement) {
                    $thisMonth = '0.00';
                    $overdue = '0.00';

                    foreach ($agreement->schedules as $schedule) {
                        $remaining = $schedule->balanceRemaining();

                        if (bccomp($remaining, '0', 2) <= 0) {
                            continue;
                        }

                        if ($schedule->status === 'overdue') {
                            $overdue = bcadd($overdue, $remaining, 2);
                        } else {
                            $thisMonth = bcadd($thisMonth, $remaining, 2);
                        }
                    }

                    return [
                        'agreement' => $agreement,
                        'thisMonth' => $thisMonth,
                        'overdue' => $overdue,
                        'total' => bcadd($thisMonth, $overdue, 2),
                    ];
                })
                ->filter(fn ($row) => bccomp($row['total'], '0', 2) > 0)
                ->values();

            return [
                'customer' => $customer,
                'agreements' => $agreementRows,
                'thisMonthTotal' => (string) $agreementRows->reduce(fn ($carry, $row) => bcadd($carry, $row['thisMonth'], 2), '0.00'),
                'overdueTotal' => (string) $agreementRows->reduce(fn ($carry, $row) => bcadd($carry, $row['overdue'], 2), '0.00'),
                'grandTotal' => (string) $agreementRows->reduce(fn ($carry, $row) => bcadd($carry, $row['total'], 2), '0.00'),
            ];
        });

        return ['rows' => $rows];
    }
} ?>

@slot('header')
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Collection Book</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Everyone due this month, plus anyone still behind from before</p>
    </div>
@endslot

<div>
    <div class="mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Search by name, CNIC, or phone…"
               class="block w-full max-w-md rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
    </div>

    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Customer</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Agreement(s)</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">This Month</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Overdue</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Total Due</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-900 dark:text-white">{{ $row['customer']->first_name }} {{ $row['customer']->last_name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['customer']->phone }}</p>
                            </td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                {{ $row['agreements']->pluck('agreement.agreement_number')->join(', ') }}
                            </td>
                            <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-200">
                                {{ bccomp($row['thisMonthTotal'], '0', 2) > 0 ? 'Rs. '.number_format((float) $row['thisMonthTotal'], 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if (bccomp($row['overdueTotal'], '0', 2) > 0)
                                    <span class="font-medium text-rose-600 dark:text-rose-400">Rs. {{ number_format((float) $row['overdueTotal'], 2) }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">
                                Rs. {{ number_format((float) $row['grandTotal'], 2) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($row['agreements']->count() === 1)
                                    <a href="{{ route('tenant.collections.desk', ['agreement' => $row['agreements'][0]['agreement']->id, 'prefillAmount' => $row['agreements'][0]['total']]) }}"
                                       wire:navigate
                                       class="inline-flex items-center rounded-lg bg-walnut-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-walnut-400">
                                        Pay
                                    </a>
                                @else
                                    <div class="flex flex-col items-end gap-1">
                                        @foreach ($row['agreements'] as $agreementRow)
                                            <a href="{{ route('tenant.collections.desk', ['agreement' => $agreementRow['agreement']->id, 'prefillAmount' => $agreementRow['total']]) }}"
                                               wire:navigate
                                               class="inline-flex items-center gap-1.5 rounded-lg bg-walnut-600 px-3 py-1 text-xs font-medium text-white hover:bg-walnut-400">
                                                {{ $agreementRow['agreement']->agreement_number }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-gray-400">
                                Nobody's due this month, and nothing's overdue. 🎉
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $rows->links() }}
    </div>
</div>
