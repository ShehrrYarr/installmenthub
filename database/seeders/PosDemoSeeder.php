<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Support\PosCalculator;
use App\Support\PosCheckout;
use App\Support\PosReturns;
use App\Support\PosSalePayments;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Turns the Point of Sale on for the demo shop — the one the landing page's
 * "Enter as…" buttons log into — and gives it something to show: a few
 * counter accessories and a week of sample sales (cash, bank, split, a credit
 * sale with a repayment, and a return).
 *
 * Safe to run again, including on the live demo: the switch is idempotent,
 * accessories are only added when missing, and sample sales are only rung up
 * for a shop that has no sales yet. Run on its own with:
 *
 *   php artisan db:seed --class=PosDemoSeeder --force
 */
class PosDemoSeeder extends Seeder
{
    /** The demo shop is whichever shop this account belongs to (see the landing page). */
    private const OWNER_EMAIL = 'admin1@installmenthub.test';

    private const MANAGER_EMAIL = 'manager1@installmenthub.test';

    private const SALESMAN_EMAIL = 'salesman1@installmenthub.test';

    /** Counter items — the catalogue ShopSeeder builds is all serialized big-ticket stock. */
    private const ACCESSORIES = [
        ['sku' => 'CHARGER-20W', 'name' => 'Fast Charger 20W', 'brand' => 'Samsung', 'cost' => 900, 'cash' => 1500, 'stock' => 40],
        ['sku' => 'EARBUDS-BT', 'name' => 'Bluetooth Earbuds', 'brand' => 'Audionic', 'cost' => 1600, 'cash' => 2500, 'stock' => 25],
        ['sku' => 'SCREEN-GUARD', 'name' => 'Tempered Glass Protector', 'brand' => 'Generic', 'cost' => 150, 'cash' => 400, 'stock' => 60],
    ];

    public function run(): void
    {
        $owner = User::where('email', self::OWNER_EMAIL)->first();
        $shop = $owner ? Shop::find($owner->shop_id) : null;

        if (! $shop) {
            $this->command?->warn('Demo shop not found ('.self::OWNER_EMAIL.' has no shop) — nothing to do.');

            return;
        }

        $shop->update(['pos_enabled' => true]);

        Tenant::set($shop);

        try {
            $this->seedAccessories($shop);

            if (Sale::query()->exists()) {
                $this->command?->info('Demo shop already has sales — left as they are.');

                return;
            }

            $this->seedSales(
                $shop,
                admin: $owner,
                manager: User::where('email', self::MANAGER_EMAIL)->first() ?? $owner,
                salesman: User::where('email', self::SALESMAN_EMAIL)->first() ?? $owner,
            );
        } finally {
            Carbon::setTestNow();
            Tenant::reset();
        }
    }

    private function seedAccessories(Shop $shop): void
    {
        foreach (self::ACCESSORIES as $item) {
            Product::firstOrCreate(
                ['shop_id' => $shop->id, 'sku' => $item['sku']],
                [
                    'name' => $item['name'],
                    'category' => 'other',
                    'brand' => $item['brand'],
                    'cost_price' => $item['cost'],
                    'cash_price' => $item['cash'],
                    'is_serialized' => false,
                    'stock_quantity' => $item['stock'],
                    'reorder_level' => 5,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedSales(Shop $shop, User $admin, User $manager, User $salesman): void
    {
        $bank = Bank::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'EasyPaisa'],
            ['account_title' => $shop->name, 'account_number' => '0300-1234567', 'is_active' => true],
        );

        $charger = Product::where('sku', 'CHARGER-20W')->first();
        $earbuds = Product::where('sku', 'EARBUDS-BT')->first();
        $guard = Product::where('sku', 'SCREEN-GUARD')->first();
        $phone = Product::where('name', 'like', '%Samsung Galaxy%')->first();
        $laptop = Product::where('name', 'like', '%HP 15s%')->first();
        $ac = Product::where('name', 'like', '%Haier%')->first();

        $customer = Customer::where('is_active', true)->where('last_name', 'No4')->first()
            ?? Customer::where('is_active', true)->orderByDesc('id')->first();

        $today = Carbon::now();
        $at = fn (int $daysAgo, int $hour) => Carbon::setTestNow($today->copy()->subDays($daysAgo)->setTime($hour, 15));

        // 1 — accessories over the counter, paid in cash.
        $at(6, 11);
        $first = $this->ring($shop, $salesman, [
            ['product' => $charger, 'quantity' => 2],
            ['product' => $earbuds, 'quantity' => 1],
        ], [['method' => 'cash', 'amount' => null]]);

        // 2 — a phone and a screen protector, paid by wallet.
        if ($phone && ($unit = $this->unit($phone))) {
            $at(5, 14);
            $this->ring($shop, $admin, [
                ['product' => $phone, 'serial' => $unit],
                ['product' => $guard],
            ], [['method' => 'bank', 'bank_id' => $bank->id, 'amount' => null, 'reference' => 'EP-DEMO-48213']]);
        }

        // 3 — a laptop with 5% off the bill.
        if ($laptop && ($unit = $this->unit($laptop))) {
            $at(4, 12);
            $this->ring($shop, $manager, [
                ['product' => $laptop, 'serial' => $unit],
            ], [['method' => 'cash', 'amount' => null]], ['bill_discount_type' => 'percent', 'bill_discount_value' => '5']);
        }

        // 4 — an AC on credit to a registered customer: Rs. 50,000 down, the rest due in two weeks.
        $credit = null;

        if ($ac && $customer && ($unit = $this->unit($ac))) {
            $at(3, 16);
            $credit = $this->ring($shop, $salesman, [
                ['product' => $ac, 'serial' => $unit],
            ], [['method' => 'cash', 'amount' => '50000']], [
                'customer_id' => $customer->id,
                'due_date' => Carbon::now()->addDays(14)->toDateString(),
            ]);
        }

        // 5 — one of the chargers from sale 1 comes back, refunded in cash.
        if ($first) {
            $at(2, 10);
            $item = $first->items->firstWhere('product_id', $charger?->id);

            if ($item) {
                (new PosReturns($shop, $admin))->process($first, [$item->id => 1], ['mode' => 'cash'], 'Charger did not fit — customer returned it');
            }
        }

        // 6 — a bigger accessory sale: 10% off the earbuds, paid part cash, part wallet.
        $at(1, 13);
        $this->ring($shop, $salesman, [
            ['product' => $earbuds, 'quantity' => 3, 'discount_type' => 'percent', 'discount_value' => '10'],
            ['product' => $charger, 'quantity' => 2],
            ['product' => $guard, 'quantity' => 2],
        ], [
            ['method' => 'cash', 'amount' => '3000'],
            ['method' => 'bank', 'bank_id' => $bank->id, 'amount' => null, 'reference' => 'EP-DEMO-48377'],
        ]);

        // 7 — the credit customer pays Rs. 20,000 towards the AC.
        if ($credit) {
            $at(1, 17);
            (new PosSalePayments($shop, $salesman))->receive($credit, ['method' => 'cash', 'amount' => '20000']);
        }

        // 8 — a sale today, so the "today" figures aren't empty.
        Carbon::setTestNow();
        $this->ring($shop, $salesman, [
            ['product' => $guard, 'quantity' => 2],
            ['product' => $charger, 'quantity' => 1],
        ], [['method' => 'cash', 'amount' => null]]);
    }

    private function unit(Product $product): ?ProductSerial
    {
        return ProductSerial::where('product_id', $product->id)->where('status', 'in_stock')->orderBy('id')->first();
    }

    /**
     * Rings a sale up through the same code the register uses.
     *
     * A payment whose amount is null takes whatever is left of the total.
     *
     * @param  array<int, array{product: ?Product, serial?: ?ProductSerial, quantity?: int, discount_type?: string, discount_value?: string}>  $lines
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<string, mixed>  $details
     */
    private function ring(Shop $shop, User $cashier, array $lines, array $payments, array $details = []): ?Sale
    {
        $lines = array_values(array_filter($lines, fn (array $line) => $line['product'] !== null));

        if ($lines === []) {
            return null;
        }

        $total = PosCalculator::totals(
            array_map(fn (array $line) => [
                'unit_price' => (string) $line['product']->cash_price,
                'quantity' => $line['quantity'] ?? 1,
                'discount_type' => $line['discount_type'] ?? PosCalculator::FIXED,
                'discount_value' => $line['discount_value'] ?? '0',
            ], $lines),
            $details['bill_discount_type'] ?? PosCalculator::FIXED,
            (string) ($details['bill_discount_value'] ?? '0'),
        )['total'];

        $paid = '0.00';

        foreach ($payments as $index => $payment) {
            if (($payment['amount'] ?? null) === null) {
                $payments[$index]['amount'] = bcsub($total, $paid, 2);
            }

            $paid = bcadd($paid, (string) $payments[$index]['amount'], 2);
        }

        return (new PosCheckout($shop, $cashier))->complete(
            array_map(fn (array $line) => [
                'product_id' => $line['product']->id,
                'product_serial_id' => ($line['serial'] ?? null)?->id,
                'quantity' => $line['quantity'] ?? 1,
                'discount_type' => $line['discount_type'] ?? PosCalculator::FIXED,
                'discount_value' => $line['discount_value'] ?? '0',
            ], $lines),
            $payments,
            $details,
        );
    }
}
