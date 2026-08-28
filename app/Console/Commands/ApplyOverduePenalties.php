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

                        if ($daysLate <= $shop->grace_period_days) {
                            continue;
                        }

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
