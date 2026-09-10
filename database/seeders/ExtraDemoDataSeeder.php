<?php

namespace Database\Seeders;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Guarantor;
use App\Models\InstallmentSchedule;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Shop;
use App\Support\EmiCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Adds a richer spread of customers/agreements on top of ShopSeeder's base
 * data — some fully paid up to date, some with a partial payment on their
 * latest installment, some overdue by varying amounts — spanning several
 * months back, so Collection Book, dashboards, and the overdue-penalty sweep
 * all have realistic, varied data to show instead of just the base seeder's
 * handful of near-identical agreements.
 *
 * Safe to run more than once and safe to run alongside ShopSeeder's own
 * agreements: uses a distinct '9xxxx' CNIC range and an 'X'-prefixed
 * agreement number so nothing collides, continues numbering from whatever
 * this seeder already added on a previous run, and silently skips any shop
 * slug it can't find rather than failing.
 */
class ExtraDemoDataSeeder extends Seeder
{
    private const SHOP_SLUGS = ['karachi-electronics-hub', 'lahore-installment-store'];

    /**
     * Per extra agreement: how many months ago it started, how many of its
     * elapsed installments are paid in full, and whether the next one after
     * those gets a partial payment. Any other already-due installment is
     * left unpaid and flagged overdue — everything not yet due stays
     * 'pending' until the real installments:apply-overdue-penalties sweep
     * (run it after seeding) computes accurate penalty amounts.
     *
     * Note: installment #1's due date is the agreement's own start date, so
     * "monthsAgo" months back means (monthsAgo + 1) installments have
     * actually elapsed — paidCount is chosen with that in mind.
     *
     * @var array<int, array{monthsAgo: int, paidCount: int, partialNext: bool}>
     */
    private array $scenarios = [
        ['monthsAgo' => 4, 'paidCount' => 5, 'partialNext' => false], // fully paid, up to date
        ['monthsAgo' => 2, 'paidCount' => 3, 'partialNext' => false], // fully paid, up to date
        ['monthsAgo' => 3, 'paidCount' => 3, 'partialNext' => true],  // partial payment on latest due installment
        ['monthsAgo' => 2, 'paidCount' => 2, 'partialNext' => true],  // partial payment on latest due installment
        ['monthsAgo' => 3, 'paidCount' => 3, 'partialNext' => false], // mildly overdue — 1 installment unpaid
        ['monthsAgo' => 4, 'paidCount' => 3, 'partialNext' => false], // mildly overdue — 2 installments unpaid
        ['monthsAgo' => 6, 'paidCount' => 2, 'partialNext' => false], // severely overdue — 5 installments unpaid
        ['monthsAgo' => 5, 'paidCount' => 0, 'partialNext' => false], // severely overdue, nothing paid
    ];

    public function run(): void
    {
        foreach (self::SHOP_SLUGS as $slug) {
            $shop = Shop::where('slug', $slug)->first();

            if (! $shop) {
                $this->command?->warn("Skipping {$slug} — no shop with that slug.");

                continue;
            }

            $this->seedExtraForShop($shop);
        }

        $this->command?->info('Run `php artisan installments:apply-overdue-penalties` next for accurate penalty amounts on the newly-overdue installments.');
    }

    private function seedExtraForShop(Shop $shop): void
    {
        $salesman = $shop->users()->role('Salesman')->first();
        $owner = $shop->owner;
        $products = Product::where('shop_id', $shop->id)->where('is_serialized', true)->get();

        if (! $salesman || ! $owner || $products->isEmpty()) {
            $this->command?->warn("Skipping {$shop->name} — missing staff or serialized products.");

            return;
        }

        $startIndex = Customer::where('shop_id', $shop->id)->where('cnic_number', 'like', '9%')->count();

        foreach ($this->scenarios as $offset => $scenario) {
            $n = $startIndex + $offset + 1;

            $customer = Customer::create([
                'shop_id' => $shop->id,
                'first_name' => "Demo{$shop->id}",
                'last_name' => "Extra{$n}",
                'cnic_number' => sprintf('9%04d-%07d-%d', $n, 900000 + $n, $n % 10),
                'phone' => '0333'.random_int(1000000, 9999999),
                'address' => "Extra Demo Street {$n}, {$shop->city}",
                'city' => $shop->city,
                'occupation' => 'Private Employee',
                'employer_name' => 'Local Business',
                'monthly_income' => 55000 + ($n * 3000),
                'home_ownership' => $n % 2 === 0 ? 'owned' : 'rented',
                'gender' => $n % 2 === 0 ? 'male' : 'female',
                'is_active' => true,
            ]);

            $product = $products[$n % $products->count()];
            $serial = ProductSerial::where('product_id', $product->id)->where('status', 'in_stock')->first();
            $downPayment = bcdiv((string) $product->cash_price, '5', 2);

            $calculator = new EmiCalculator(
                productPrice: (string) $product->cash_price,
                downPayment: $downPayment,
                processingFee: (string) $shop->default_processing_fee,
                interestRate: (string) $shop->default_interest_rate,
                durationMonths: 12,
            );

            $startDate = now()->subMonths($scenario['monthsAgo'])->startOfMonth();
            $schedule = $calculator->schedule($startDate);

            $agreement = Agreement::create([
                'shop_id' => $shop->id,
                'customer_id' => $customer->id,
                'salesman_id' => $salesman->id,
                'agreement_number' => 'AGR-'.$shop->id.'-X'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'status' => 'active',
                'product_price' => $product->cash_price,
                'down_payment' => $downPayment,
                'processing_fee' => $shop->default_processing_fee,
                'interest_rate' => $shop->default_interest_rate,
                'duration_months' => 12,
                'financed_amount' => $calculator->financedAmount(),
                'total_interest' => $calculator->totalInterest(),
                'total_payable' => $calculator->totalPayable(),
                'monthly_installment' => $calculator->monthlyInstallment(),
                'start_date' => $startDate,
                'first_due_date' => $schedule[0]['due_date'],
                'approved_by' => $owner->id,
                'approved_at' => $startDate,
            ]);

            AgreementItem::create([
                'shop_id' => $shop->id,
                'agreement_id' => $agreement->id,
                'product_id' => $product->id,
                'product_serial_id' => $serial?->id,
                'quantity' => 1,
                'unit_price' => $product->cash_price,
            ]);

            $serial?->update(['status' => 'sold', 'sold_at' => $startDate]);

            foreach (['Guarantor One', 'Guarantor Two'] as $g => $label) {
                Guarantor::create([
                    'shop_id' => $shop->id,
                    'agreement_id' => $agreement->id,
                    'name' => "{$label} for {$customer->first_name}",
                    'cnic_number' => sprintf('9%04d-%07d-%d', 8000 + $n, 800000 + $g, $g),
                    'mobile_number' => '0334'.random_int(1000000, 9999999),
                    'relation' => $g === 0 ? 'Brother' : 'Friend',
                    'work_address' => "Extra Office {$g}, {$shop->city}",
                ]);
            }

            $runningBalance = $calculator->totalPayable();

            CustomerLedgerEntry::create([
                'shop_id' => $shop->id,
                'customer_id' => $customer->id,
                'agreement_id' => $agreement->id,
                'type' => 'debit',
                'amount' => $calculator->totalPayable(),
                'running_balance' => $runningBalance,
                'reference_type' => Agreement::class,
                'reference_id' => $agreement->id,
                'description' => "New agreement — {$agreement->ledgerLabel()}",
                'entry_date' => $startDate->toDateString(),
                'created_by' => $owner->id,
            ]);

            foreach ($schedule as $i => $row) {
                $installment = InstallmentSchedule::create(array_merge($row, [
                    'shop_id' => $shop->id,
                    'agreement_id' => $agreement->id,
                ]));

                $dueDate = Carbon::parse($row['due_date']);

                if ($dueDate->isFuture()) {
                    continue; // stays 'pending' — not due yet
                }

                if ($i < $scenario['paidCount']) {
                    $paidAt = $dueDate->copy()->subDays(2)->max($startDate->copy()->addDay());
                    $runningBalance = $this->recordPayment($shop, $customer, $agreement, $installment, $row['total_due'], $paidAt, $salesman, $runningBalance);
                    $installment->update(['amount_paid' => $row['total_due'], 'status' => 'paid', 'paid_at' => $paidAt]);
                } elseif ($i === $scenario['paidCount'] && $scenario['partialNext']) {
                    $fraction = bcdiv((string) random_int(60, 80), '100', 2);
                    $partialAmount = bcmul($row['total_due'], $fraction, 2);
                    $paidAt = $dueDate->copy()->subDays(1)->max($startDate->copy()->addDay());
                    $runningBalance = $this->recordPayment($shop, $customer, $agreement, $installment, $partialAmount, $paidAt, $salesman, $runningBalance);
                    $installment->update([
                        'amount_paid' => $partialAmount,
                        'status' => $dueDate->isPast() ? 'overdue' : 'partial',
                    ]);
                } else {
                    $installment->update(['status' => 'overdue']);
                }
            }
        }
    }

    private function recordPayment(Shop $shop, Customer $customer, Agreement $agreement, InstallmentSchedule $installment, string $amount, Carbon $paidAt, $salesman, string $runningBalance): string
    {
        $payment = Payment::create([
            'shop_id' => $shop->id,
            'agreement_id' => $agreement->id,
            'installment_schedule_id' => $installment->id,
            'customer_id' => $customer->id,
            'amount' => $amount,
            'payment_mode' => 'cash',
            'received_by' => $salesman->id,
            'receipt_number' => 'RCP-'.$shop->id.'-X'.$agreement->id.'-'.$installment->installment_number,
            'paid_at' => $paidAt,
        ]);

        $runningBalance = bcsub($runningBalance, $amount, 2);

        CustomerLedgerEntry::create([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'agreement_id' => $agreement->id,
            'type' => 'credit',
            'amount' => $amount,
            'running_balance' => $runningBalance,
            'reference_type' => Payment::class,
            'reference_id' => $payment->id,
            'description' => "Installment payment — {$agreement->ledgerLabel()}",
            'entry_date' => $paidAt->toDateString(),
            'created_by' => $salesman->id,
        ]);

        return $runningBalance;
    }
}
