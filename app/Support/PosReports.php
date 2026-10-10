<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the counter has sold and earned, and what is in the drawer.
 *
 * Money follows the same rules as Sale: what was *kept* is what counts, so a
 * returned unit comes out of revenue and out of cost alike.
 *
 *   revenue  = total − returned_total
 *   cost     = Σ unit cost × units still with the customer
 *   profit   = revenue − cost
 *
 * The SQL fragments below mirror Sale::balanceDue() and friends so a list can
 * filter and add up in the database; the unit tests keep the two in step.
 */
final class PosReports
{
    /** Cost price is the shop's own business — only owners and managers see profit. */
    public static function canSeeProfit(?User $user): bool
    {
        return $user?->hasAnyRole(['Shop Admin', 'Manager', 'Super Admin']) ?? false;
    }

    /** Still to collect on a sale (never negative) — Sale::balanceDue(). */
    public static function balanceSql(string $sales = 'sales'): string
    {
        $owed = "(({$sales}.total - {$sales}.returned_total) - ({$sales}.amount_paid - {$sales}.refunded_total))";

        return "(CASE WHEN {$owed} > 0 THEN {$owed} ELSE 0 END)";
    }

    /** What the units still with the customer cost the shop. */
    public static function costSql(string $sales = 'sales'): string
    {
        return '(COALESCE((SELECT SUM(sale_items.cost_price * (sale_items.quantity - sale_items.returned_quantity)) '
            ."FROM sale_items WHERE sale_items.sale_id = {$sales}.id), 0))";
    }

    public static function profitSql(string $sales = 'sales'): string
    {
        return "(({$sales}.total - {$sales}.returned_total) - ".self::costSql($sales).')';
    }

    /**
     * Adds up a filtered set of sales.
     *
     * @return array{count: int, gross: string, returned: string, net: string, collected: string, balance_due: string, cost: string, profit: string}
     */
    public static function totals(Builder $sales): array
    {
        $row = (clone $sales)->toBase()->reorder()->selectRaw(
            'COUNT(*) as sales_count, '
            .'COALESCE(SUM(sales.total), 0) as gross, '
            .'COALESCE(SUM(sales.returned_total), 0) as returned, '
            .'COALESCE(SUM(sales.amount_paid - sales.refunded_total), 0) as collected, '
            .'COALESCE(SUM('.self::balanceSql().'), 0) as balance_due, '
            .'COALESCE(SUM('.self::costSql().'), 0) as cost'
        )->first();

        $gross = self::money($row->gross);
        $returned = self::money($row->returned);
        $net = bcsub($gross, $returned, 2);
        $cost = self::money($row->cost);

        return [
            'count' => (int) $row->sales_count,
            'gross' => $gross,
            'returned' => $returned,
            'net' => $net,
            'collected' => self::money($row->collected),
            'balance_due' => self::money($row->balance_due),
            'cost' => $cost,
            'profit' => bcsub($net, $cost, 2),
        ];
    }

    /**
     * Units, revenue and profit per product over a period, best earner first.
     * A product's revenue is its lines' share of what was actually charged —
     * after its own discount and its slice of any bill discount.
     *
     * @return Collection<int, object{product_id: int, name: string, sold: int, returned: int, net_units: int, revenue: string, cost: string, profit: string}>
     */
    public static function byProduct(Carbon $from, Carbon $to): Collection
    {
        // 1.0 * keeps SQLite (which stores whole numbers as integers) from
        // truncating the division; MySQL's DECIMALs are unaffected.
        $billShare = '(1.0 * (sales.subtotal - sales.line_discount_total - sales.bill_discount_amount)'
            .' / (sales.subtotal - sales.line_discount_total))';

        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereBetween('sales.sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('sale_items.product_id')
            ->selectRaw(
                'sale_items.product_id, MAX(sale_items.product_name) as name, '
                .'SUM(sale_items.quantity) as sold, SUM(sale_items.returned_quantity) as returned, '
                ."SUM(1.0 * sale_items.line_total * (sale_items.quantity - sale_items.returned_quantity) / sale_items.quantity * {$billShare}) as revenue, "
                .'SUM(sale_items.cost_price * (sale_items.quantity - sale_items.returned_quantity)) as cost'
            )
            ->get()
            ->map(function ($row) {
                $revenue = self::money($row->revenue);
                $cost = self::money($row->cost);

                return (object) [
                    'product_id' => (int) $row->product_id,
                    'name' => (string) $row->name,
                    'sold' => (int) $row->sold,
                    'returned' => (int) $row->returned,
                    'net_units' => (int) $row->sold - (int) $row->returned,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => bcsub($revenue, $cost, 2),
                ];
            })
            ->sortByDesc(fn ($row) => (float) $row->revenue)
            ->values();
    }

    /**
     * Sales, revenue and profit per salesman over a period, best seller first.
     *
     * @return Collection<int, object{user_id: ?int, name: string, count: int, gross: string, returned: string, net: string, profit: string}>
     */
    public static function bySalesman(Carbon $from, Carbon $to): Collection
    {
        return Sale::query()
            ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('sold_by')
            ->selectRaw(
                'sold_by, COUNT(*) as sales_count, SUM(total) as gross, SUM(returned_total) as returned, '
                .'SUM('.self::costSql().') as cost'
            )
            ->with('seller:id,name')
            ->get()
            ->map(function (Sale $row) {
                $gross = self::money($row->gross);
                $returned = self::money($row->returned);
                $net = bcsub($gross, $returned, 2);

                return (object) [
                    'user_id' => $row->sold_by,
                    'name' => $row->seller?->name ?? 'Unknown',
                    'count' => (int) $row->sales_count,
                    'gross' => $gross,
                    'returned' => $returned,
                    'net' => $net,
                    'profit' => bcsub($net, self::money($row->cost), 2),
                ];
            })
            ->sortByDesc(fn ($row) => (float) $row->net)
            ->values();
    }

    /**
     * The day at the till: what came in by each route, what went back out,
     * and so how much cash should be in the drawer.
     *
     * Money is dated by when it moved — a repayment taken today on a sale
     * made last week is today's cash.
     *
     * @return array<string, mixed>
     */
    public static function day(Carbon $date): array
    {
        $day = $date->toDateString();

        $payments = SalePayment::with(['bank', 'sale:id,sold_at'])->whereDate('paid_at', $day)->get();
        $returns = SaleReturn::with('bank')->whereDate('returned_at', $day)->get();
        $sales = Sale::with('payments')->whereDate('sold_at', $day)->get();

        $sum = fn (Collection $rows, string $column) => $rows->reduce(
            fn (string $carry, $row) => bcadd($carry, (string) $row->{$column}, 2),
            '0.00'
        );

        $cashPayments = $payments->where('payment_mode', PaymentMethod::CASH);
        $bankPayments = $payments->where('payment_mode', PaymentMethod::BANK);

        $bankLabel = fn ($row) => $row->bank?->name ?? 'Bank';

        // Credit extended today: what today's sales left unpaid by the end of today.
        $newCredit = $sales->reduce(function (string $carry, Sale $sale) use ($day) {
            $paidToday = $sale->payments
                ->filter(fn (SalePayment $payment) => $payment->paid_at->toDateString() === $day)
                ->reduce(fn (string $sum, SalePayment $payment) => bcadd($sum, (string) $payment->amount, 2), '0.00');

            $left = bcsub((string) $sale->total, $paidToday, 2);

            return bccomp($left, '0', 2) > 0 ? bcadd($carry, $left, 2) : $carry;
        }, '0.00');

        $cashRefunds = $returns->where('payment_mode', PaymentMethod::CASH);
        $bankRefunds = $returns->where('payment_mode', PaymentMethod::BANK);

        return [
            'date' => $day,
            'sales_count' => $sales->count(),
            'sales_total' => $sum($sales, 'total'),
            'discounts' => bcadd($sum($sales, 'line_discount_total'), $sum($sales, 'bill_discount_amount'), 2),
            'returns_count' => $returns->count(),
            'returns_value' => $sum($returns, 'total_value'),
            'new_credit' => $newCredit,
            'cash_in' => $sum($cashPayments, 'amount'),
            'bank_in' => $sum($bankPayments, 'amount'),
            'bank_in_by_bank' => $bankPayments->groupBy($bankLabel)->map(fn (Collection $rows) => $sum($rows, 'amount'))->all(),
            // Money taken today against sales made on an earlier day.
            'repayments' => $payments
                ->filter(fn (SalePayment $payment) => $payment->sale && $payment->sale->sold_at->toDateString() !== $day)
                ->reduce(fn (string $carry, SalePayment $payment) => bcadd($carry, (string) $payment->amount, 2), '0.00'),
            'cash_refunds' => $sum($cashRefunds, 'refund_amount'),
            'bank_refunds' => $sum($bankRefunds, 'refund_amount'),
            'ledger_credits' => $sum($returns->where('payment_mode', SaleReturn::MODE_CREDIT), 'credit_amount'),
        ];
    }

    /** A DB aggregate (a string on MySQL, a float on SQLite) as a 2dp decimal string. */
    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
