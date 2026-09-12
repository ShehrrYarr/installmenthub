<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\InstallmentSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared query/aggregation logic behind the Collection Book, used by both
 * the interactive page (Livewire\collections\book) and the printable sheet
 * (CollectionBookPrintController) so the two never drift apart.
 */
class CollectionBook
{
    /**
     * Agreement statuses that never carry a real, collectible schedule.
     */
    private const EXCLUDED_AGREEMENT_STATUSES = ['draft', 'pending_approval', 'cancelled'];

    /**
     * One entry per area, each holding its customers (sorted by name) and
     * an area subtotal. Areas are sorted by name, with customers who have
     * no address grouped last under "No Area Listed".
     *
     * @return Collection<int, array{area: string, customers: Collection, areaTotal: string}>
     */
    public static function groupedRows(string $search = '', ?string $month = null): Collection
    {
        return static::customerRows($search, $month)
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
     * Build one row per customer: every schedule due on or before the
     * selected month that still had money outstanding as of that month's
     * end, split into "this month" (due within the selected month) and
     * "overdue" (already past the shop's grace period by that date).
     *
     * For the current month this reads live data (today's status/balance).
     * For a past month it's a historical snapshot: balances are replayed
     * from actual payment dates, and the overdue cutoff is recomputed with
     * the shop's grace period rather than trusted from the `status` column,
     * since that column only reflects where things stand *today*.
     *
     * @return Collection<int, array{customer: Customer, agreements: Collection, thisMonthTotal: string, overdueTotal: string, grandTotal: string}>
     */
    public static function customerRows(string $search = '', ?string $month = null): Collection
    {
        $month = static::resolveMonth($month);
        $isCurrentMonth = $month === now()->format('Y-m');

        $period = Carbon::createFromFormat('Y-m', $month);
        $startOfMonth = $period->copy()->startOfMonth();
        $endOfMonth = $period->copy()->endOfMonth();
        $snapshot = $isCurrentMonth ? now() : $endOfMonth->copy()->endOfDay();

        $shop = Tenant::current();
        // Matches the overdue sweep: grace only holds the flag back while
        // penalties are actually being charged.
        $graceDays = $shop?->effectiveGraceDays() ?? 0;

        $scheduleQuery = fn ($query) => $query->where('due_date', '<=', $endOfMonth->toDateString());

        $customers = Customer::query()
            ->where('is_active', true)
            ->whereHas('agreements', fn ($q) => $q->whereNotIn('status', self::EXCLUDED_AGREEMENT_STATUSES)
                ->whereHas('schedules', $scheduleQuery))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('cnic_number', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->with(['agreements' => fn ($q) => $q->whereNotIn('status', self::EXCLUDED_AGREEMENT_STATUSES)
                ->with([
                    'schedules' => fn ($q2) => $q2->where('due_date', '<=', $endOfMonth->toDateString())
                        ->with(['payments' => fn ($p) => $p->where('paid_at', '<=', $snapshot)]),
                    'items.product',
                ])])
            ->orderBy('first_name')
            ->get();

        return $customers->map(function (Customer $customer) use ($isCurrentMonth, $graceDays, $snapshot) {
            $agreementRows = $customer->agreements
                ->map(function ($agreement) use ($isCurrentMonth, $graceDays, $snapshot) {
                    $thisMonth = '0.00';
                    $overdue = '0.00';

                    foreach ($agreement->schedules as $schedule) {
                        $remaining = $isCurrentMonth
                            ? $schedule->balanceRemaining()
                            : bcsub((string) $schedule->total_due, static::paidAsOf($schedule), 2);

                        if (bccomp($remaining, '0', 2) <= 0) {
                            continue;
                        }

                        $isOverdue = $isCurrentMonth
                            ? $schedule->status === 'overdue'
                            : static::wasOverdueAsOf($schedule, $graceDays, $snapshot);

                        if ($isOverdue) {
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
        })->filter(fn (array $row) => $row['agreements']->isNotEmpty())->values();
    }

    /**
     * Summary numbers for the selected month — unaffected by the customer
     * search filter, so they always reflect the whole month:
     *  - totalAmount: everything scheduled to be due that month (paid or not)
     *  - totalReceivable: the unpaid portion of that month's own dues
     *  - totalOverdue: unpaid balance carried over from before that month
     *
     * totalReceivable/totalOverdue reuse the same historical-snapshot logic
     * as {@see customerRows()} so they never drift from the list below them.
     *
     * @return array{totalAmount: string, totalReceivable: string, totalOverdue: string}
     */
    public static function monthSummary(?string $month = null): array
    {
        $month = static::resolveMonth($month);
        $period = Carbon::createFromFormat('Y-m', $month);
        $startOfMonth = $period->copy()->startOfMonth()->toDateString();
        $endOfMonth = $period->copy()->endOfMonth()->toDateString();

        $rows = static::customerRows('', $month);

        $totalAmount = (string) InstallmentSchedule::query()
            ->whereBetween('due_date', [$startOfMonth, $endOfMonth])
            ->whereHas('agreement', fn ($q) => $q->whereNotIn('status', self::EXCLUDED_AGREEMENT_STATUSES))
            ->sum('total_due');

        return [
            'totalAmount' => $totalAmount,
            'totalReceivable' => (string) $rows->reduce(fn ($carry, $row) => bcadd($carry, $row['thisMonthTotal'], 2), '0.00'),
            'totalOverdue' => (string) $rows->reduce(fn ($carry, $row) => bcadd($carry, $row['overdueTotal'], 2), '0.00'),
        ];
    }

    /**
     * Selectable months for the filter, newest first, spanning from the
     * shop's earliest scheduled installment up to the current month.
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    public static function availableMonths(): Collection
    {
        $currentMonth = now()->startOfMonth();
        $earliestDue = InstallmentSchedule::query()->min('due_date');
        $start = $earliestDue ? Carbon::parse($earliestDue)->startOfMonth() : $currentMonth->copy();

        if ($start->greaterThan($currentMonth)) {
            $start = $currentMonth->copy();
        }

        $months = collect();
        $cursor = $currentMonth->copy();

        while ($cursor->greaterThanOrEqualTo($start)) {
            $months->push([
                'value' => $cursor->format('Y-m'),
                'label' => $cursor->format('F Y'),
            ]);
            $cursor->subMonth();
        }

        return $months;
    }

    /**
     * Normalize a requested month to "Y-m", falling back to (and clamping
     * to) the current month — browsing ahead into the future isn't
     * meaningful since nothing's due yet.
     */
    public static function resolveMonth(?string $month): string
    {
        $current = now()->format('Y-m');

        if ($month === null || $month === '' || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $current;
        }

        return $month > $current ? $current : $month;
    }

    private static function paidAsOf(InstallmentSchedule $schedule): string
    {
        return (string) $schedule->payments->reduce(fn ($carry, $payment) => bcadd($carry, (string) $payment->amount, 2), '0.00');
    }

    private static function wasOverdueAsOf(InstallmentSchedule $schedule, int $graceDays, Carbon $snapshot): bool
    {
        $dueDate = Carbon::parse($schedule->due_date);

        if ($dueDate->greaterThan($snapshot)) {
            return false;
        }

        return $dueDate->diffInDays($snapshot) > $graceDays;
    }

    private static function areaLabel(?string $address): string
    {
        $trimmed = trim((string) $address);

        return $trimmed === '' ? 'No Area Listed' : $trimmed;
    }
}
