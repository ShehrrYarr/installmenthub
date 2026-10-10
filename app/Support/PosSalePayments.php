<?php

namespace App\Support;

use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money received against a sale that still has a balance — the part of a
 * credit sale the customer didn't pay at the till.
 *
 * Errors are keyed `payment` / `payment.0.amount` / `payment.0.bank_id` …
 * for the sale page's receive-payment form.
 */
final class PosSalePayments
{
    public function __construct(
        private readonly Shop $shop,
        private readonly User $user,
    ) {}

    /**
     * @param  array{method?: string, bank_id?: int|string|null, amount?: string, reference?: ?string, tendered?: ?string, proof?: mixed}  $payment
     */
    public function receive(Sale $sale, array $payment): SalePayment
    {
        // Livewire actions skip the route group's check — see PosCheckout.
        abort_unless($this->shop->posEnabled(), 404);
        abort_unless($sale->shop_id === $this->shop->id, 404);

        return DB::transaction(function () use ($sale, $payment) {
            // Locked and re-read: two tills taking the last of a balance at the
            // same moment must not both succeed.
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasBalanceDue()) {
                throw $this->fail('payment', 'This sale has nothing left to pay.');
            }

            $lines = new PosPaymentLines($this->shop, $this->user);
            $paid = $lines->validate([$payment], 'payment');

            if (bccomp($paid, '0', 2) <= 0) {
                throw $this->fail('payment.0.amount', 'Enter the amount received.');
            }

            $balance = $locked->balanceDue();

            if (bccomp($paid, $balance, 2) > 0) {
                throw $this->fail('payment.0.amount', 'That is more than the balance of '.PosCalculator::money($balance).'.');
            }

            $customer = $locked->customer;
            $stored = $lines->store($locked, [$payment], $customer);

            $locked->update(['amount_paid' => bcadd((string) $locked->amount_paid, $paid, 2)]);

            if ($customer) {
                $lines->postToLedger($locked, $customer, $stored);
                CustomerLedgerEntry::recalculateFor($customer->id);
            }

            return $stored[0];
        });
    }

    private function fail(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }
}
