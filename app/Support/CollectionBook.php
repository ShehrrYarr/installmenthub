<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Shared query/aggregation logic behind the Collection Book, used by both
 * the interactive page (Livewire\collections\book) and the printable sheet
 * (CollectionBookPrintController) so the two never drift apart.
 */
class CollectionBook
{
    /**
     * One entry per area, each holding its customers (sorted by name) and
     * an area subtotal. Areas are sorted by name, with customers who have
     * no address grouped last under "No Area Listed".
     *
     * @return Collection<int, array{area: string, customers: Collection, areaTotal: string}>
     */
    public static function groupedRows(string $search = ''): Collection
    {
        return static::customerRows($search)
            ->groupBy(fn (array $row) => static::areaLabel($row['customer']->address))
            ->sortKeys()
            ->map(fn (Collection $customers, string $area) => [
                'area' => $area,
                'customers' => $customers->values(),
                'areaTotal' => (string) $customers->reduce(fn ($carry, $row) => bcadd($carry, $row['grandTotal'], 2), '0.00'),
            ])
            ->values()
            ->sortBy(fn (array $group) => $group['area'] === 'No Area Listed' ? 1 : 0)
            ->values();
    }

    /**
     * Build one row per customer: every active agreement of theirs that
     * still has money outstanding, split into "this month" (due within the
     * current calendar month, not yet overdue) and "overdue" (carried over
     * from a prior month — status already flipped to 'overdue', matching
     * the same flag the dashboard and penalty sweep use).
     *
     * @return Collection<int, array{customer: Customer, agreements: Collection, thisMonthTotal: string, overdueTotal: string, grandTotal: string}>
     */
    public static function customerRows(string $search = ''): Collection
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
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('cnic_number', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->with(['agreements' => fn ($q) => $q->where('status', 'active')
                ->with(['schedules' => $dueThisPeriod, 'items.product'])])
            ->orderBy('first_name')
            ->get();

        return $customers->map(function (Customer $customer) {
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
                        'units' => $agreement->items->map(fn ($item) => $item->product?->name)->filter()->join(', '),
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
    }

    private static function areaLabel(?string $address): string
    {
        $trimmed = trim((string) $address);

        return $trimmed === '' ? 'No Area Listed' : $trimmed;
    }
}
