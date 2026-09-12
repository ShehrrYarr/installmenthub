<?php

namespace App\Console\Commands;

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

        Shop::where('is_active', true)->each(function (Shop $shop) use ($today, &$flagged) {
            InstallmentSchedule::where('shop_id', $shop->id)
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->where('due_date', '<', $today)
                ->chunkById(100, function ($schedules) use ($shop, $today, &$flagged) {
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
                        }

                        $schedule->status = 'overdue';
                        $schedule->save();

                        $flagged++;
                    }
                });
        });

        $this->info("Overdue sweep complete — {$flagged} installment(s) flagged/updated.");

        return self::SUCCESS;
    }
}
