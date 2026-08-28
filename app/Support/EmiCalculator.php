<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Flat-rate EMI math shared by the calculator UI, agreement creation, and seeders.
 *
 * financed        = (price - down payment) + processing fee
 * total_interest  = financed * (rate / 100) * (months / 12)
 * total_payable   = financed + total_interest
 * monthly_installment = total_payable / months
 *
 * All money math runs through bcmath (2-decimal scale) to avoid float drift.
 */
final class EmiCalculator
{
    public function __construct(
        public readonly string $productPrice,
        public readonly string $downPayment,
        public readonly string $processingFee,
        public readonly string $interestRate,
        public readonly int $durationMonths,
    ) {}

    public function financedAmount(): string
    {
        return bcadd(bcsub($this->productPrice, $this->downPayment, 2), $this->processingFee, 2);
    }

    public function totalInterest(): string
    {
        if ($this->durationMonths <= 0) {
            return '0.00';
        }

        $rateFraction = bcdiv($this->interestRate, '100', 6);
        $durationFraction = bcdiv((string) $this->durationMonths, '12', 6);

        $interest = bcmul(bcmul($this->financedAmount(), $rateFraction, 6), $durationFraction, 6);

        return bcadd($interest, '0', 2);
    }

    public function totalPayable(): string
    {
        return bcadd($this->financedAmount(), $this->totalInterest(), 2);
    }

    public function monthlyInstallment(): string
    {
        if ($this->durationMonths <= 0) {
            return '0.00';
        }

        return bcdiv($this->totalPayable(), (string) $this->durationMonths, 2);
    }

    /**
     * @return array<int, array{
     *     installment_number: int, due_date: string, opening_balance: string,
     *     principal_component: string, interest_component: string,
     *     total_due: string, closing_balance: string,
     * }>
     */
    public function schedule(string|CarbonInterface $startDate): array
    {
        if ($this->durationMonths <= 0) {
            return [];
        }

        $start = Carbon::parse($startDate);
        $installment = $this->monthlyInstallment();
        $principalPerMonth = bcdiv($this->financedAmount(), (string) $this->durationMonths, 2);
        $interestPerMonth = bcdiv($this->totalInterest(), (string) $this->durationMonths, 2);

        $rows = [];
        $opening = $this->totalPayable();

        for ($i = 1; $i <= $this->durationMonths; $i++) {
            $isLast = $i === $this->durationMonths;

            // The last installment pays off whatever remains on the running
            // balance, so its principal component absorbs the rounding
            // remainder and the schedule always closes at 0.00.
            $due = $isLast ? $opening : $installment;
            $interest = $interestPerMonth;
            $principal = bcsub($due, $interest, 2);
            $closing = bcsub($opening, $due, 2);

            $rows[] = [
                'installment_number' => $i,
                'due_date' => $start->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                'opening_balance' => $opening,
                'principal_component' => $principal,
                'interest_component' => $interest,
                'total_due' => $due,
                'closing_balance' => bccomp($closing, '0', 2) > 0 ? $closing : '0.00',
            ];

            $opening = $closing;
        }

        return $rows;
    }
}
