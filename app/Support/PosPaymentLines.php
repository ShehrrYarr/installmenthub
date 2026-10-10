<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The payment lines of a counter sale — validated, stored and posted to the
 * customer ledger the same way whether they arrive with the sale itself or
 * later, as a repayment against it.
 *
 * A line is `method`, `bank_id`, `amount`, `reference`, `tendered`, `proof`.
 * A line without an amount is simply unused (the register leaves a spare
 * payment row empty on a credit sale) and is skipped throughout; indices are
 * preserved so a validation error still points at the row it came from.
 */
final class PosPaymentLines
{
    public function __construct(
        private readonly Shop $shop,
        private readonly User $user,
    ) {}

    /**
     * Checks every used line and returns what they add up to.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @param  string  $field  prefix for error keys: "{$field}.{n}.amount" …
     */
    public function validate(array $payments, string $field = 'payments'): string
    {
        $bankIds = PaymentMethod::banks()->pluck('id')->all();
        $paid = '0.00';

        foreach ($this->used($payments) as $index => $payment) {
            $method = $payment['method'] ?? null;

            if (! in_array($method, PaymentMethod::methods(), true)) {
                throw $this->fail("{$field}.{$index}.method", 'Choose Cash or Bank.');
            }

            $amount = PosCalculator::number($payment['amount'] ?? '0');

            if ($method === PaymentMethod::BANK && ! in_array((int) ($payment['bank_id'] ?? 0), $bankIds, true)) {
                throw $this->fail("{$field}.{$index}.bank_id", 'Please select a bank.');
            }

            $tendered = PosCalculator::number($payment['tendered'] ?? '0');

            if ($method === PaymentMethod::CASH && bccomp($tendered, '0', 2) > 0 && bccomp($tendered, $amount, 2) < 0) {
                throw $this->fail("{$field}.{$index}.tendered", 'Cash received is less than the amount being paid.');
            }

            $paid = bcadd($paid, $amount, 2);
        }

        return $paid;
    }

    /**
     * Writes the used lines as SalePayments, numbering each receipt after the
     * ones the sale already has (S-000012-P1, -P2, …).
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<int, SalePayment>
     */
    public function store(Sale $sale, array $payments, ?Customer $customer): array
    {
        $stored = [];
        $number = SalePayment::where('sale_id', $sale->id)->count();

        foreach ($this->used($payments) as $payment) {
            [$mode, $bankId] = PaymentMethod::toStorage($payment['method'], $payment['bank_id'] ?? null);

            $tendered = PosCalculator::number($payment['tendered'] ?? '0');

            $record = SalePayment::create([
                'shop_id' => $this->shop->id,
                'sale_id' => $sale->id,
                'customer_id' => $customer?->id,
                'amount' => PosCalculator::number($payment['amount']),
                'tendered' => $mode === PaymentMethod::CASH && bccomp($tendered, '0', 2) > 0 ? $tendered : null,
                'payment_mode' => $mode,
                'bank_id' => $bankId,
                'reference_number' => $mode === PaymentMethod::BANK ? $this->clean($payment['reference'] ?? null) : null,
                'receipt_number' => $sale->sale_number.'-P'.(++$number),
                'received_by' => $this->user->id,
                'paid_at' => now(),
            ]);

            $proof = $payment['proof'] ?? null;

            // Cash leaves no slip — a proof left over from a moment when Bank
            // was selected is simply not stored.
            if ($mode === PaymentMethod::BANK && $proof instanceof UploadedFile) {
                $record->addMedia($proof->getRealPath())
                    ->usingFileName($proof->getClientOriginalName())
                    ->toMediaCollection(ReceiptProof::COLLECTION);
            }

            $stored[] = $record;
        }

        return $stored;
    }

    /**
     * One ledger credit per payment. The caller recalculates the running
     * balance once everything for the customer has been posted.
     *
     * @param  array<int, SalePayment>  $payments
     */
    public function postToLedger(Sale $sale, Customer $customer, array $payments): void
    {
        foreach ($payments as $payment) {
            CustomerLedgerEntry::create([
                'shop_id' => $this->shop->id,
                'customer_id' => $customer->id,
                'agreement_id' => null,
                'type' => 'credit',
                'amount' => $payment->amount,
                'payment_mode' => $payment->payment_mode,
                'bank_id' => $payment->bank_id,
                'running_balance' => '0.00',
                'reference_type' => SalePayment::class,
                'reference_id' => $payment->id,
                'description' => "Payment received — {$sale->sale_number} ({$payment->receipt_number})",
                'entry_date' => now()->toDateString(),
                'created_by' => $this->user->id,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<int, array<string, mixed>>
     */
    private function used(array $payments): array
    {
        return array_filter(
            $payments,
            fn (array $payment) => bccomp(PosCalculator::number($payment['amount'] ?? '0'), '0', 2) > 0
        );
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function fail(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }
}
