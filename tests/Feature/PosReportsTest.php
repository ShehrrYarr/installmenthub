<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Customer;
use App\Models\DayClosing;
use App\Models\HeldCart;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Support\PosReports;
use App\Support\Tenant;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * POS phase 3: sales reports and profit, the day's closing count, and
 * sales held while another customer is served.
 */
class PosReportsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $salesman;

    private User $manager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->shop = Shop::create([
            'name' => 'POS Shop', 'slug' => 'pos-shop', 'is_active' => true, 'pos_enabled' => true,
            'address' => '1 Market Rd', 'phone' => '021-111',
        ]);

        $this->salesman = $this->staff('Salesman');
        $this->manager = $this->staff('Manager');
        $this->admin = $this->staff('Shop Admin');

        $this->as($this->salesman);
    }

    private function staff(string $role, ?Shop $shop = null): User
    {
        $user = User::factory()->create(['shop_id' => ($shop ?? $this->shop)->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user, ?Shop $shop = null): void
    {
        $shop ??= $this->shop;

        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);
        $this->actingAs($user);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'shop_id' => $this->shop->id, 'name' => 'Cable', 'category' => 'other', 'sku' => 'CAB-1',
            'cash_price' => '500', 'cost_price' => '300', 'is_serialized' => false,
            'stock_quantity' => 50, 'reorder_level' => 2, 'is_active' => true,
        ], $overrides));
    }

    private function customer(string $cnic = '11111-1111111-1'): Customer
    {
        return Customer::create([
            'shop_id' => $this->shop->id, 'first_name' => 'Jane', 'last_name' => 'Doe',
            'cnic_number' => $cnic, 'phone' => '0300'.random_int(1000000, 9999999), 'is_active' => true,
        ]);
    }

    /** Rings up `$quantity` of a product as the signed-in user. */
    private function sell(Product $product, int $quantity = 1, array $set = [], ?Customer $customer = null): Sale
    {
        $register = Volt::test('pos.register');

        if ($customer) {
            $register->set('customer_id', $customer->id);
        }

        for ($i = 0; $i < $quantity; $i++) {
            $register->call('addProduct', $product->id);
        }

        foreach ($set as $key => $value) {
            $register->set($key, $value);
        }

        $register->call('checkout')->assertHasNoErrors();

        return Sale::orderByDesc('id')->firstOrFail();
    }

    private function giveBack(Sale $sale, int $quantity, string $mode = 'cash'): void
    {
        Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')
            ->set('returnQty.0', (string) $quantity)
            ->call('setRefundMode', $mode)
            ->call('submitReturn')
            ->assertHasNoErrors();
    }

    // ── Profit and reports ─────────────────────────────────────────────

    public function test_profit_is_revenue_kept_less_what_it_cost_and_follows_returns(): void
    {
        $cable = $this->product();                                           // 500, cost 300
        $lamp = $this->product(['name' => 'Lamp', 'sku' => 'LMP-1', 'cash_price' => '1000', 'cost_price' => '700']);

        $a = $this->sell($cable, 2);                                         // 1000, cost 600
        $this->as($this->manager);
        $b = $this->sell($lamp, 1, ['billDiscountType' => 'percent', 'billDiscountValue' => '10']);   // 900, cost 700

        $this->assertSame('400.00', $a->fresh()->load('items')->profit());
        $this->assertSame('200.00', $b->fresh()->load('items')->profit());

        $this->giveBack($a, 1);                                              // one cable back: kept 500, cost 300

        $a = $a->fresh()->load('items');
        $this->assertSame('300.00', $a->keptCost());
        $this->assertSame('200.00', $a->profit());

        // The database sums must agree with the model, sale by sale.
        $totals = PosReports::totals(Sale::query());

        $this->assertSame(2, $totals['count']);
        $this->assertSame('1900.00', $totals['gross']);
        $this->assertSame('500.00', $totals['returned']);
        $this->assertSame('1400.00', $totals['net']);
        $this->assertSame('1000.00', $totals['cost']);
        $this->assertSame('400.00', $totals['profit']);
    }

    public function test_reports_break_sales_down_by_product_and_by_salesman(): void
    {
        $cable = $this->product();
        $lamp = $this->product(['name' => 'Lamp', 'sku' => 'LMP-1', 'cash_price' => '1000', 'cost_price' => '700']);

        $a = $this->sell($cable, 2);                                         // by the salesman
        $this->as($this->manager);
        $this->sell($lamp, 1, ['billDiscountType' => 'percent', 'billDiscountValue' => '10']);
        $this->giveBack($a, 1);

        $from = today();

        $products = PosReports::byProduct($from, $from)->keyBy('name');

        $this->assertSame(2, $products['Cable']->sold);
        $this->assertSame(1, $products['Cable']->returned);
        $this->assertSame(1, $products['Cable']->net_units);
        $this->assertSame('500.00', $products['Cable']->revenue);
        $this->assertSame('300.00', $products['Cable']->cost);
        $this->assertSame('200.00', $products['Cable']->profit);

        // The Rs. 1,000 lamp went for Rs. 900 after 10% off the bill.
        $this->assertSame('900.00', $products['Lamp']->revenue);
        $this->assertSame('700.00', $products['Lamp']->cost);
        $this->assertSame('200.00', $products['Lamp']->profit);

        $people = PosReports::bySalesman($from, $from)->keyBy('user_id');

        $this->assertSame(1, $people[$this->salesman->id]->count);
        $this->assertSame('500.00', $people[$this->salesman->id]->net);
        $this->assertSame('200.00', $people[$this->salesman->id]->profit);
        $this->assertSame('900.00', $people[$this->manager->id]->net);
        $this->assertSame('200.00', $people[$this->manager->id]->profit);
    }

    public function test_the_reports_page_is_for_owners_and_managers_only(): void
    {
        $this->withoutVite();
        $this->sell($this->product());

        $this->as($this->salesman);
        $this->get("/s/{$this->shop->slug}/sales/reports")->assertForbidden();

        $this->as($this->manager);
        $this->get("/s/{$this->shop->slug}/sales/reports")->assertOk()->assertSee('Sales by product');

        $this->as($this->admin);
        $this->get("/s/{$this->shop->slug}/sales/reports")->assertOk();

        // Not a sale id.
        $this->as($this->salesman);
        $this->get("/s/{$this->shop->slug}/sales/closing")->assertOk();
    }

    public function test_cost_and_profit_are_hidden_from_a_salesman(): void
    {
        $this->withoutVite();
        $sale = $this->sell($this->product(['name' => 'Charger']));

        $this->get("/s/{$this->shop->slug}/sales/{$sale->id}")
            ->assertOk()->assertSee('Charger')->assertDontSee('Cost of goods kept')->assertDontSee('Profit');

        $this->get("/s/{$this->shop->slug}/sales")
            ->assertOk()->assertDontSee('Profit');

        $this->as($this->manager);

        $this->get("/s/{$this->shop->slug}/sales/{$sale->id}")
            ->assertOk()->assertSee('Cost of goods kept')->assertSee('Profit');

        $this->get("/s/{$this->shop->slug}/sales")->assertOk()->assertSee('Profit');
    }

    // ── Sales list filters and totals ──────────────────────────────────

    public function test_sales_list_filters_by_date_salesman_method_and_balance_and_totals_what_matches(): void
    {
        $bank = Bank::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'is_active' => true]);
        $cable = $this->product();
        $customer = $this->customer();

        // Three days ago, by the salesman, cash.
        $this->travelTo(now()->subDays(3));
        $old = $this->sell($cable, 1);
        $this->travelBack();

        // Today, by the manager, paid by bank.
        $this->as($this->manager);
        $viaBank = Volt::test('pos.register')->call('addProduct', $cable->id)->call('addProduct', $cable->id)
            ->call('setPaymentMethod', 0, 'bank')->set('payments.0.bank_id', (string) $bank->id)->call('checkout');
        $viaBank->assertHasNoErrors();

        // Today, by the salesman, part on credit.
        $this->as($this->salesman);
        $credit = $this->sell($cable, 2, ['payments.0.amount' => '300', 'dueDate' => now()->addDays(7)->toDateString()], $customer);

        $list = Volt::test('pos.sales');

        $list->assertSee($old->sale_number)->assertSee($credit->sale_number);
        $this->assertSame(3, $list->get('totals')['count']);

        $today = today()->toDateString();

        $list->set('from', $today);
        $this->assertSame(2, $list->get('totals')['count']);
        $list->assertDontSee($old->sale_number);

        $list->set('method', 'bank');
        $this->assertSame(1, $list->get('totals')['count']);
        $this->assertSame('1000.00', $list->get('totals')['gross']);

        $list->set('method', 'all')->set('salesman', (string) $this->salesman->id);
        $this->assertSame(1, $list->get('totals')['count']);
        $this->assertSame('700.00', $list->get('totals')['balance_due']);     // 1000 − 300 paid

        $list->set('salesman', '')->set('filter', 'outstanding');
        $this->assertSame(1, $list->get('totals')['count']);
        $list->assertSee($credit->sale_number);

        $list->call('clearFilters');
        $this->assertSame(3, $list->get('totals')['count']);
    }

    // ── Day closing ────────────────────────────────────────────────────

    public function test_the_day_summary_separates_cash_bank_refunds_and_credit(): void
    {
        $bank = Bank::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'is_active' => true]);
        $cable = $this->product();
        $customer = $this->customer();

        // Sold last week on credit — Rs. 100 of it is repaid today.
        $this->travelTo(now()->subDays(5));
        $older = $this->sell($cable, 1, ['payments.0.amount' => '200', 'dueDate' => now()->addDays(20)->toDateString()], $customer);
        $this->travelBack();

        $cash = $this->sell($cable, 1);                                       // 500 cash
        Volt::test('pos.register')->call('addProduct', $cable->id)->call('addProduct', $cable->id)
            ->call('setPaymentMethod', 0, 'bank')->set('payments.0.bank_id', (string) $bank->id)->call('checkout');   // 1000 bank
        $this->sell($cable, 1, ['payments.0.amount' => '200', 'dueDate' => now()->addDays(7)->toDateString()], $customer);  // 200 cash, 300 on credit

        Volt::test('pos.show', ['sale' => $older])
            ->call('togglePaymentForm')->set('payAmount', '100')->call('receivePayment')->assertHasNoErrors();      // 100 cash, old sale

        $this->giveBack($cash, 1);                                            // 500 cash refund

        $day = PosReports::day(today());

        $this->assertSame(3, $day['sales_count']);
        $this->assertSame('2000.00', $day['sales_total']);
        $this->assertSame('800.00', $day['cash_in']);                        // 500 + 200 + 100
        $this->assertSame('1000.00', $day['bank_in']);
        $this->assertSame(['Meezan' => '1000.00'], $day['bank_in_by_bank']);
        $this->assertSame('100.00', $day['repayments']);
        $this->assertSame('300.00', $day['new_credit']);
        $this->assertSame('500.00', $day['cash_refunds']);
        $this->assertSame(1, $day['returns_count']);
        $this->assertSame('500.00', $day['returns_value']);

        // Yesterday had none of it.
        $yesterday = PosReports::day(today()->subDay());
        $this->assertSame(0, $yesterday['sales_count']);
        $this->assertSame('0.00', $yesterday['cash_in']);
    }

    public function test_closing_the_day_records_the_count_and_flags_a_shortage(): void
    {
        $cable = $this->product();

        $sale = $this->sell($cable, 2);                                       // 1000 cash
        $this->giveBack($sale, 1);                                            // 500 cash back

        // Opening float 100 + 1000 taken − 500 refunded = 600 expected.
        $closing = Volt::test('pos.closing')
            ->set('openingFloat', '100')
            ->set('countedCash', '580');

        $this->assertSame('600.00', $closing->instance()->expected());

        $closing->call('save')->assertHasNoErrors();

        $saved = DayClosing::firstOrFail();

        $this->assertSame(today()->toDateString(), $saved->business_date->toDateString());
        $this->assertSame('600.00', (string) $saved->expected_cash);
        $this->assertSame('580.00', (string) $saved->counted_cash);
        $this->assertSame('-20.00', (string) $saved->difference);
        $this->assertTrue($saved->isShort());
        $this->assertSame($this->salesman->id, $saved->closed_by);
        $this->assertSame('1000.00', $saved->summary['cash_in']);

        // Counting again replaces the day's closing rather than adding another.
        $closing->set('countedCash', '600')->call('save')->assertHasNoErrors();

        $this->assertSame(1, DayClosing::count());
        $this->assertSame('0.00', (string) DayClosing::firstOrFail()->difference);
    }

    public function test_a_count_is_required_and_a_saved_day_reloads_its_figures(): void
    {
        $this->sell($this->product());

        Volt::test('pos.closing')->call('save')->assertHasErrors(['countedCash']);
        $this->assertSame(0, DayClosing::count());

        Volt::test('pos.closing')->set('openingFloat', '50')->set('countedCash', '550')->set('notes', 'All good')->call('save');

        Volt::test('pos.closing')
            ->assertSet('openingFloat', '50.00')
            ->assertSet('countedCash', '550.00')
            ->assertSet('notes', 'All good');
    }

    public function test_closings_belong_to_their_shop(): void
    {
        $other = Shop::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true, 'pos_enabled' => true]);

        DayClosing::create([
            'shop_id' => $other->id, 'business_date' => today()->toDateString(), 'opening_float' => 0, 'cash_in' => 0,
            'cash_refunds' => 0, 'expected_cash' => 0, 'counted_cash' => 999, 'difference' => 999, 'closed_at' => now(),
        ]);

        Volt::test('pos.closing')->assertSet('countedCash', '');     // not the other shop's 999

        $this->assertCount(0, Volt::test('pos.closing')->get('history'));
    }

    // ── Held sales ─────────────────────────────────────────────────────

    public function test_a_sale_can_be_held_and_the_register_starts_fresh(): void
    {
        $cable = $this->product();
        $customer = $this->customer();

        Volt::test('pos.register')
            ->set('customer_id', $customer->id)
            ->call('addProduct', $cable->id)
            ->call('addProduct', $cable->id)
            ->set('cart.0.discount_type', 'fixed')
            ->set('cart.0.discount_value', '100')
            ->call('holdSale')
            ->assertRedirect();

        $held = HeldCart::firstOrFail();

        $this->assertSame($customer->id, $held->customer_id);
        $this->assertSame(2, $held->item_count);
        $this->assertSame('900.00', (string) $held->total_estimate);
        $this->assertSame($this->salesman->id, $held->held_by);
        $this->assertSame($cable->id, $held->cart[0]['product_id']);
        $this->assertSame('100', $held->cart[0]['discount_value']);
        $this->assertSame(0, Sale::count());                           // nothing sold, nothing taken out of stock
        $this->assertSame(50, $cable->fresh()->availableQuantity());
    }

    public function test_an_empty_register_has_nothing_to_hold(): void
    {
        Volt::test('pos.register')->call('holdSale')->assertSet('noticeIsError', true);

        $this->assertSame(0, HeldCart::count());
    }

    public function test_a_held_sale_resumes_with_its_customer_discount_and_quantities(): void
    {
        $cable = $this->product();
        $customer = $this->customer();

        Volt::test('pos.register')
            ->set('customer_id', $customer->id)
            ->call('addProduct', $cable->id)->call('addProduct', $cable->id)
            ->set('billDiscountType', 'percent')->set('billDiscountValue', '10')
            ->set('notes', 'For Friday')
            ->call('holdSale');

        $held = HeldCart::firstOrFail();

        // (Volt's own withQueryParams() is bound to a different manager and is
        // not picked up by Volt::test() — the Livewire one is.)
        \Livewire\Livewire::withQueryParams(['resume' => $held->id]);
        $register = Volt::test('pos.register');

        $register
            ->assertSet('customer_id', $customer->id)
            ->assertSet('customerLabel', 'Jane Doe')
            ->assertSet('billDiscountType', 'percent')
            ->assertSet('billDiscountValue', '10')
            ->assertSet('notes', 'For Friday')
            ->assertSet('cart.0.quantity', 2);

        // It is off the held list once it is back on the register — and sells as normal.
        $this->assertSame(0, HeldCart::count());

        $register->call('checkout')->assertHasNoErrors();

        $this->assertSame('900.00', (string) Sale::firstOrFail()->total);
    }

    public function test_resuming_drops_what_is_no_longer_available(): void
    {
        $cable = $this->product(['stock_quantity' => 3]);
        $phone = $this->product(['name' => 'Phone', 'sku' => 'PHN', 'cash_price' => '50000', 'is_serialized' => true, 'stock_quantity' => 2]);

        $a = ProductSerial::create(['shop_id' => $this->shop->id, 'product_id' => $phone->id, 'serial_number' => 'IMEI-A', 'status' => 'in_stock']);
        $b = ProductSerial::create(['shop_id' => $this->shop->id, 'product_id' => $phone->id, 'serial_number' => 'IMEI-B', 'status' => 'in_stock']);

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)->call('addProduct', $cable->id)->call('addProduct', $cable->id)
            ->call('addSerial', $a->id)->call('addSerial', $b->id)
            ->call('holdSale');

        $held = HeldCart::firstOrFail();

        // While it was parked: one phone sold on the other till, and most of the cable stock too.
        $a->update(['status' => 'sold']);
        $cable->update(['stock_quantity' => 1]);

        // (Volt's own withQueryParams() is bound to a different manager and is
        // not picked up by Volt::test() — the Livewire one is.)
        \Livewire\Livewire::withQueryParams(['resume' => $held->id]);
        $register = Volt::test('pos.register');

        $cart = collect($register->get('cart'));

        $this->assertSame(1, $cart->where('product_id', $cable->id)->sum('quantity'));      // 3 wanted, 1 left
        $this->assertSame([$b->id], $cart->pluck('product_serial_id')->filter()->values()->all());
        $register->assertSet('noticeIsError', true);
    }

    public function test_resuming_is_refused_while_a_sale_is_in_progress(): void
    {
        $cable = $this->product();

        Volt::test('pos.register')->call('addProduct', $cable->id)->call('holdSale');
        $held = HeldCart::firstOrFail();

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->call('resumeHeld', $held->id)
            ->assertSet('noticeIsError', true)
            ->assertNoRedirect();

        $this->assertSame(1, HeldCart::count());
    }

    public function test_held_sales_are_shared_in_the_shop_but_not_across_shops(): void
    {
        $cable = $this->product();

        Volt::test('pos.register')->call('addProduct', $cable->id)->call('holdSale');
        $held = HeldCart::firstOrFail();

        // A colleague on another till sees it, and can discard it.
        $this->as($this->manager);
        $list = Volt::test('pos.register');
        $this->assertCount(1, $list->get('heldCarts'));

        // Another shop does not.
        $other = Shop::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true, 'pos_enabled' => true]);
        $stranger = $this->staff('Shop Admin', $other);
        $this->as($stranger, $other);

        $theirs = Volt::test('pos.register');
        $this->assertCount(0, $theirs->get('heldCarts'));

        $theirs->call('discardHeld', $held->id);
        $this->assertSame(1, HeldCart::withoutGlobalScopes()->count());      // untouched

        $this->as($this->manager);
        Volt::test('pos.register')->call('discardHeld', $held->id);
        $this->assertSame(0, HeldCart::count());
    }
}
