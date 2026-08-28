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
use App\Models\User;
use App\Models\Vendor;
use App\Support\EmiCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ShopSeeder extends Seeder
{
    private array $productCatalog = [
        ['name' => 'Samsung Galaxy A15', 'category' => 'mobile', 'brand' => 'Samsung', 'model' => 'A15', 'cost' => 38000, 'cash' => 46000],
        ['name' => 'HP 15s Laptop', 'category' => 'laptop', 'brand' => 'HP', 'model' => '15s', 'cost' => 95000, 'cash' => 112000],
        ['name' => 'Haier 1.5 Ton Inverter AC', 'category' => 'ac', 'brand' => 'Haier', 'model' => 'HSU-18', 'cost' => 130000, 'cash' => 155000],
        ['name' => 'Dawlance Refrigerator 9188', 'category' => 'refrigerator', 'brand' => 'Dawlance', 'model' => '9188', 'cost' => 78000, 'cash' => 92000],
        ['name' => 'Inverex Solar Inverter 5kW', 'category' => 'solar_inverter', 'brand' => 'Inverex', 'model' => 'Nitrox 5kW', 'cost' => 210000, 'cash' => 248000],
    ];

    public function run(): void
    {
        $superAdmin = User::create([
            'name' => 'Platform Super Admin',
            'email' => 'superadmin@installmenthub.test',
            'password' => 'password',
            'shop_id' => null,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $superAdmin->assignRole('Super Admin');

        $shops = collect([
            ['name' => 'Karachi Electronics Hub', 'city' => 'Karachi'],
            ['name' => 'Lahore Installment Store', 'city' => 'Lahore'],
        ])->map(fn ($config, $index) => $this->seedShop($config, $index + 1));

        $agreementsPerShop = [3, 2]; // totals 5 active agreements across the two shops

        $shops->each(function ($seeded, $index) use ($agreementsPerShop) {
            $this->seedAgreements($seeded, $agreementsPerShop[$index]);
        });
    }

    /**
     * @return array{shop: Shop, owner: User, manager: User, salesman: User, vendor: Vendor}
     */
    private function seedShop(array $config, int $index): array
    {
        $shop = Shop::create([
            'name' => $config['name'],
            'slug' => Str::slug($config['name']),
            'city' => $config['city'],
            'phone' => '021'.random_int(1000000, 9999999),
            'currency_code' => 'PKR',
            'timezone' => 'Asia/Karachi',
            'subscription_status' => 'active',
            'billing_cycle' => 'monthly',
            'monthly_fee' => 4999,
            'subscription_started_at' => now()->subMonths(3),
            'default_interest_rate' => 12,
            'default_processing_fee' => 500,
            'penalty_type' => 'daily',
            'penalty_rate' => 50,
            'grace_period_days' => 3,
            'is_active' => true,
        ]);

        $owner = User::create([
            'name' => "Shop Admin {$index}",
            'email' => "admin{$index}@installmenthub.test",
            'password' => 'password',
            'shop_id' => $shop->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $owner->assignRole('Shop Admin');
        $shop->update(['owner_user_id' => $owner->id]);

        $manager = User::create([
            'name' => "Manager {$index}",
            'email' => "manager{$index}@installmenthub.test",
            'password' => 'password',
            'shop_id' => $shop->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $manager->assignRole('Manager');

        $salesman = User::create([
            'name' => "Salesman {$index}",
            'email' => "salesman{$index}@installmenthub.test",
            'password' => 'password',
            'shop_id' => $shop->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $salesman->assignRole('Salesman');

        $vendor = Vendor::create([
            'shop_id' => $shop->id,
            'name' => "{$config['city']} Electronics Distributors",
            'contact_person' => 'Vendor Contact',
            'phone' => '0300'.random_int(1000000, 9999999),
            'bank_name' => 'Meezan Bank',
            'bank_account_title' => "{$config['city']} Electronics Distributors",
            'bank_account_number' => (string) random_int(1000000000, 9999999999),
            'is_active' => true,
        ]);

        collect($this->productCatalog)->each(function ($item) use ($shop, $vendor) {
            $isSerialized = in_array($item['category'], ['mobile', 'laptop', 'ac', 'refrigerator', 'solar_inverter']);

            $product = Product::create([
                'shop_id' => $shop->id,
                'vendor_id' => $vendor->id,
                'name' => $item['name'],
                'sku' => Str::upper(Str::slug($item['name'], '')).'-'.$shop->id,
                'category' => $item['category'],
                'brand' => $item['brand'],
                'model' => $item['model'],
                'cost_price' => $item['cost'],
                'cash_price' => $item['cash'],
                'is_serialized' => $isSerialized,
                'stock_quantity' => 5,
                'reorder_level' => 2,
                'is_active' => true,
            ]);

            if ($isSerialized) {
                for ($i = 0; $i < 5; $i++) {
                    ProductSerial::create([
                        'shop_id' => $shop->id,
                        'product_id' => $product->id,
                        'serial_number' => strtoupper(Str::random(2)).random_int(100000000000000, 999999999999999),
                        'status' => 'in_stock',
                    ]);
                }
            }
        });

        collect(range(1, 5))->each(function ($n) use ($shop, $index) {
            Customer::create([
                'shop_id' => $shop->id,
                'first_name' => "Customer{$index}",
                'last_name' => "No{$n}",
                'cnic_number' => sprintf('%05d-%07d-%d', 10000 + $index, 100000 + $n, $n % 10),
                'phone' => '0301'.random_int(1000000, 9999999),
                'address' => "House {$n}, {$shop->city}",
                'city' => $shop->city,
                'occupation' => 'Private Employee',
                'employer_name' => 'Local Business',
                'monthly_income' => 60000 + ($n * 5000),
                'home_ownership' => $n % 2 === 0 ? 'owned' : 'rented',
                'gender' => $n % 2 === 0 ? 'male' : 'female',
                'is_active' => true,
            ]);
        });

        return ['shop' => $shop, 'owner' => $owner, 'manager' => $manager, 'salesman' => $salesman, 'vendor' => $vendor];
    }

    /**
     * @param  array{shop: Shop, owner: User, manager: User, salesman: User, vendor: Vendor}  $seeded
     */
    private function seedAgreements(array $seeded, int $count): void
    {
        ['shop' => $shop, 'owner' => $owner, 'salesman' => $salesman] = $seeded;

        $customers = Customer::where('shop_id', $shop->id)->limit($count)->get();
        $products = Product::where('shop_id', $shop->id)->where('is_serialized', true)->get();

        foreach (range(1, $count) as $n) {
            $customer = $customers[$n - 1];
            $product = $products[$n % $products->count()];
            $serial = ProductSerial::where('product_id', $product->id)->where('status', 'in_stock')->first();

            $downPayment = bcdiv((string) $product->cash_price, '5', 2); // 20% down

            $calculator = new EmiCalculator(
                productPrice: (string) $product->cash_price,
                downPayment: $downPayment,
                processingFee: (string) $shop->default_processing_fee,
                interestRate: (string) $shop->default_interest_rate,
                durationMonths: 12,
            );

            $startDate = now()->subMonths(2)->startOfMonth();
            $schedule = $calculator->schedule($startDate);

            $agreement = Agreement::create([
                'shop_id' => $shop->id,
                'customer_id' => $customer->id,
                'salesman_id' => $salesman->id,
                'agreement_number' => 'AGR-'.$shop->id.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
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
                    'cnic_number' => sprintf('%05d-%07d-%d', 20000 + $n, 200000 + $g, $g),
                    'mobile_number' => '0302'.random_int(1000000, 9999999),
                    'relation' => $g === 0 ? 'Brother' : 'Friend',
                    'work_address' => "Office {$g}, {$shop->city}",
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
                'description' => "New agreement — {$agreement->agreement_number}",
                'entry_date' => $startDate->toDateString(),
                'created_by' => $owner->id,
            ]);

            // Realistic history: the first two installments are already paid,
            // the third is left overdue so dashboards/collection desk have live data.
            foreach ($schedule as $i => $row) {
                $installment = InstallmentSchedule::create(array_merge($row, [
                    'shop_id' => $shop->id,
                    'agreement_id' => $agreement->id,
                ]));

                if ($i < 2) {
                    // Installment #1's due date is the agreement's own start date, so a
                    // naive "2 days before due" would date the payment before the
                    // agreement existed — clamp it to start the day after instead.
                    $paidAt = \Illuminate\Support\Carbon::parse($row['due_date'])
                        ->subDays(2)
                        ->max($startDate->copy()->addDay());

                    $payment = Payment::create([
                        'shop_id' => $shop->id,
                        'agreement_id' => $agreement->id,
                        'installment_schedule_id' => $installment->id,
                        'customer_id' => $customer->id,
                        'amount' => $row['total_due'],
                        'payment_mode' => 'cash',
                        'received_by' => $salesman->id,
                        'receipt_number' => 'RCP-'.$shop->id.'-'.$agreement->id.'-'.($i + 1),
                        'paid_at' => $paidAt,
                    ]);

                    $installment->update([
                        'amount_paid' => $row['total_due'],
                        'status' => 'paid',
                        'paid_at' => $paidAt,
                    ]);

                    $runningBalance = bcsub($runningBalance, $row['total_due'], 2);

                    CustomerLedgerEntry::create([
                        'shop_id' => $shop->id,
                        'customer_id' => $customer->id,
                        'agreement_id' => $agreement->id,
                        'type' => 'credit',
                        'amount' => $row['total_due'],
                        'running_balance' => $runningBalance,
                        'reference_type' => Payment::class,
                        'reference_id' => $payment->id,
                        'description' => "Installment payment — {$agreement->agreement_number}",
                        'entry_date' => $paidAt->toDateString(),
                        'created_by' => $salesman->id,
                    ]);
                } elseif ($i === 2) {
                    $installment->update(['status' => 'overdue']);
                }
            }
        }
    }
}
