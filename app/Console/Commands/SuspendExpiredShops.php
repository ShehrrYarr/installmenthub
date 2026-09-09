<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Suspends shops whose paid subscription period has run out. A Super Admin
 * brings them back by recording the next payment (Shops → Reactivate), which
 * is the only thing that moves next_billing_date forward.
 */
class SuspendExpiredShops extends Command
{
    protected $signature = 'shops:suspend-expired';

    protected $description = 'Suspend active shops whose subscription period has ended';

    public function handle(): int
    {
        $expired = Shop::where('subscription_status', 'active')
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '<', today())
            ->get();

        foreach ($expired as $shop) {
            $shop->update([
                'subscription_status' => 'suspended',
                'suspended_at' => now(),
                'suspension_reason' => 'Subscription expired on '.$shop->next_billing_date->format('d M Y'),
            ]);

            $this->line("Suspended {$shop->name} (expired {$shop->next_billing_date->format('d M Y')})");
        }

        $this->info("{$expired->count()} shop(s) suspended.");

        return self::SUCCESS;
    }
}
