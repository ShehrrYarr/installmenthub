<?php

namespace App\Console\Commands;

use App\Models\CustomerLedgerEntry;
use App\Models\InstallmentSchedule;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily overdue/penalty sweep (see Shop::penalty_type/penalty_rate/grace_period_days).
 *
 * Flags pending/partial installments as overdue once they clear the shop's grace
 * period, then (re)computes penalty_amount so the run is idempotent — it can
 * fire every day without double-charging. 'daily' penalties grow with the days
 * elapsed past the grace period; 'fixed' penalties are a flat one-time charge.
 *
 * Each time a schedule's penalty grows, the increase is also posted as a
 * CustomerLedgerEntry debit — otherwise the customer's own statement/ledger
 * would understate what Collection Desk says is actually owed once a
 * penalty lands (the two read from different places: Collection Desk sums
 * schedule.total_due directly, the ledger is its own running balance).
 *
 * A shop with penalties switched off still gets its overdue flags — the
 * dashboard, Collection Book and customer portal all read them — but nothing
 * is charged, and already-billed penalties are left alone.
 */
class ApplyOverduePenalties extends Command
{
    protected $signature = 'installments:apply-overdue-penalties';

    protected $description = 'Flag overdue installment schedules and (re)calculate their late-payment penalties';

    public function handle(): int
    {
        $today = Carbon::today();
        $flagged = 0;

        /** @var array<int, true> customer_id => true, recalculated once per customer after all their entries are posted */
        $touchedCustomerIds = [];

        Shop::where('is_active', true)->each(function (Shop $shop) use ($today, &$flagged, &$touchedCustomerIds) {
            InstallmentSchedule::where('shop_id', $shop->id)
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->where('due_date', '<', $today)
                ->with('agreement:id,customer_id')
                ->chunkById(100, function ($schedules) use ($shop, $today, &$flagged, &$touchedCustomerIds) {
                    foreach ($schedules as $schedule) {
                        // Carbon::diffInDays() is signed toward its argument, so call it
                        // on the (earlier) due date to get a positive "days late" count.
                        $daysLate = Carbon::parse($schedule->due_date)->diffInDays($today);

                        // With penalties off the grace period has nothing to
                        // delay, so an unpaid instalment is overdue the day after
                        // it was due.
                        if ($daysLate <= $shop->effectiveGraceDays()) {
                            continue;
                        }

                        // Charges already on a schedule are money the customer
                        // owes; switching penalties off stops new ones rather
                        // than writing off what's been billed. So penalty_amount
                        // and total_due are left exactly as they are.
                        if ($shop->penaltiesEnabled()) {
                            // Read from the ledger itself, not schedule.penalty_amount — the
                            // column can already hold a charge that predates this reconciliation
                            // (e.g. one applied before this ledger-posting existed). Summing what's
                            // actually been posted makes a first run after that self-healing: it
                            // catches up the difference instead of treating the old value as
                            // already accounted for.
                            $alreadyPosted = (string) CustomerLedgerEntry::where('reference_type', InstallmentSchedule::class)
                                ->where('reference_id', $schedule->id)
                                ->sum('amount');

                            $effectiveDaysLate = $daysLate - $shop->grace_period_days;

                            $penalty = $shop->penalty_type === 'daily'
                                ? bcmul((string) $shop->penalty_rate, (string) $effectiveDaysLate, 2)
                                : (string) $shop->penalty_rate;

                            $schedule->penalty_amount = $penalty;
                            $schedule->total_due = bcadd(
                                bcadd((string) $schedule->principal_component, (string) $schedule->interest_component, 2),
                                $penalty,
                                2
                            );

                            $increase = bcsub($penalty, $alreadyPosted, 2);
                            $customerId = $schedule->agreement?->customer_id;

                            if ($customerId !== null && bccomp($increase, '0.00', 2) > 0) {
                                CustomerLedgerEntry::create([
                                    'shop_id' => $shop->id,
                                    'customer_id' => $customerId,
                                    'agreement_id' => $schedule->agreement_id,
                                    'type' => 'debit',
                                    'amount' => $increase,
                                    // Recalculated in bulk once per customer below —
                                    // this placeholder is never read as final.
                                    'running_balance' => '0.00',
                                    'reference_type' => InstallmentSchedule::class,
                                    'reference_id' => $schedule->id,
                                    'description' => "Late payment penalty — installment #{$schedule->installment_number}",
                                    'entry_date' => $today->toDateString(),
                                ]);

                                $touchedCustomerIds[$customerId] = true;
                            }
                        }

                        $schedule->status = 'overdue';
                        $schedule->save();

                        $flagged++;
                    }
                });
        });

        foreach (array_keys($touchedCustomerIds) as $customerId) {
            CustomerLedgerEntry::recalculateFor($customerId);
        }

        $this->info("Overdue sweep complete — {$flagged} installment(s) flagged/updated.");

        return self::SUCCESS;
    }
}
