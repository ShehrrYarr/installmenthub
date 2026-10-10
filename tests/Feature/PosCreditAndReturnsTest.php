<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenant;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * POS phase 2: sales on credit, repayments, returns and refunds, and the
 * customer portal's view of all of it.
 */
class PosCreditAndReturnsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $cashier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->shop = Shop::create([
            'name' => 'POS Shop', 'slug' => 'pos-shop', 'is_active' => true, 'pos_enabled' => true,
            'address' => '1 Market Rd', 'phone' => '021-111',
        ]);

        $this->cashier = User::factory()->create(['shop_id' => $this->shop->id, 'is_active' => true]);
        $this->cashier->assignRole('Salesman');

        $this->customer = $this->customer();

        $this->signIn($this->shop, $this->cashier);
    }

    private function signIn(Shop $shop, User $user): void
    {
        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);
        $this->actingAs($user);
    }

    /**
     * Portal requests carry only the `customer` guard — the default guard stays
     * `web` with nobody on it, which Tenant::resolve() relies on. actingAs()
     * would otherwise make `customer` the default and break that.
     */
    private function asCustomer(Customer $customer): void
    {
        \Illuminate\Support\Facades\Auth::guard('web')->logout();
        $this->actingAs($customer, 'customer');
        \Illuminate\Support\Facades\Auth::shouldUse('web');
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'shop_id' => $this->shop->id, 'name' => 'Cable', 'category' => 'other', 'sku' => 'CAB-1',
            'cash_price' => '500', 'cost_price' => '300', 'is_serialized' => false,
            'stock_quantity' => 10, 'reorder_level' => 2, 'is_active' => true,
        ], $overrides));
    }

    private function phone(string ...$imeis): Product
    {
        $product = $this->product([
            'name' => 'Phone X', 'sku' => 'PHN-X', 'category' => 'mobile', 'cash_price' => '50000',
            'cost_price' => '42000', 'is_serialized' => true, 'stock_quantity' => count($imeis),
        ]);

        foreach ($imeis as $imei) {
            ProductSerial::create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'serial_number' => $imei, 'status' => 'in_stock']);
        }

        return $product;
    }

    private function customer(string $cnic = '11111-1111111-1', ?Shop $shop = null): Customer
    {
        return Customer::create([
            'shop_id' => ($shop ?? $this->shop)->id, 'first_name' => 'Jane', 'last_name' => 'Doe',
            'cnic_number' => $cnic, 'phone' => '0300'.random_int(1000000, 9999999), 'is_active' => true,
        ]);
    }

    private function bank(): Bank
    {
        return Bank::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'is_active' => true]);
    }

    /** Rings up `$quantity` of a product for the customer, paying `$paid` now (null = all of it). */
    private function sell(Product $product, int $quantity = 1, ?string $paid = null, ?Customer $customer = null, array $set = []): Sale
    {
        $register = Volt::test('pos.register')->set('customer_id', ($customer ?? $this->customer)->id);

        for ($i = 0; $i < $quantity; $i++) {
            $register->call('addProduct', $product->id);
        }

        if ($paid !== null) {
            $register->set('payments.0.amount', $paid);
        }

        foreach ($set as $key => $value) {
            $register->set($key, $value);
        }

        $register->call('checkout')->assertHasNoErrors();

        return Sale::orderByDesc('id')->firstOrFail();
    }

    private function balanceOf(Customer $customer): string
    {
        return (string) CustomerLedgerEntry::where('customer_id', $customer->id)
            ->orderBy('entry_date')->orderBy('id')->get()->last()?->running_balance;
    }

    public function test_customer_search_matches_a_full_name_and_stays_in_this_shop(): void
    {
        $other = Shop::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
        $this->customer('99999-9999999-9', $other);

        $register = Volt::test('pos.register')->instance();

        $this->assertSame([$this->customer->id], array_column($register->searchCustomers('Jane Doe'), 'id'));
        $this->assertSame([$this->customer->id], array_column($register->searchCustomers('doe jane'), 'id'));
        $this->assertSame([], $register->searchCustomers('Jane Smith'));
        $this->assertCount(1, $register->searchCustomers('Jane'));   // the other shop's Jane Doe isn't offered
    }

    // ── Sales on credit ────────────────────────────────────────────────

    public function test_part_payment_leaves_a_balance_on_credit_with_a_due_date(): void
    {
        $cable = $this->product();
        $due = now()->addDays(10)->toDateString();

        $sale = $this->sell($cable, 2, '400', set: ['dueDate' => $due]);

        $this->assertSame('1000.00', (string) $sale->total);
        $this->assertSame('400.00', (string) $sale->amount_paid);
        $this->assertSame('600.00', $sale->balanceDue());
        $this->assertSame($due, $sale->due_date->toDateString());
        $this->assertSame('partial', $sale->paymentState());

        // The ledger carries exactly what is still owed.
        $this->assertSame('600.00', $this->balanceOf($this->customer));
        $this->assertSame(1, SalePayment::count());
        $this->assertSame('S-000001-P1', SalePayment::firstOrFail()->receipt_number);
    }

    public function test_a_sale_can_be_taken_entirely_on_credit(): void
    {
        $cable = $this->product();

        $register = Volt::test('pos.register')
            ->set('customer_id', $this->customer->id)
            ->call('addProduct', $cable->id)
            ->call('payNothingNow')
            ->call('checkout')
            ->assertHasNoErrors();

        $sale = Sale::firstOrFail();

        $this->assertSame('0.00', (string) $sale->amount_paid);
        $this->assertSame('500.00', $sale->balanceDue());
        $this->assertSame(0, SalePayment::count());
        $this->assertSame('500.00', $this->balanceOf($this->customer));
        $this->assertSame('On credit', $sale->load('payments')->paymentSummary());
    }

    public function test_a_balance_cannot_be_left_to_a_walk_in(): void
    {
        $cable = $this->product();

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->set('payments.0.amount', '100')
            ->call('checkout')
            ->assertHasErrors(['payments']);

        $this->assertSame(0, Sale::count());
    }

    public function test_credit_needs_a_valid_due_date_that_is_not_in_the_past(): void
    {
        $cable = $this->product();

        $register = Volt::test('pos.register')
            ->set('customer_id', $this->customer->id)
            ->call('addProduct', $cable->id)
            ->set('payments.0.amount', '100');

        $register->set('dueDate', '')->call('checkout')->assertHasErrors(['dueDate']);
        $register->set('dueDate', now()->subDay()->toDateString())->call('checkout')->assertHasErrors(['dueDate']);

        $this->assertSame(0, Sale::count());

        // Today is fine.
        $register->set('dueDate', now()->toDateString())->call('checkout')->assertHasNoErrors();
        $this->assertSame(1, Sale::count());
    }

    public function test_paying_more_than_the_total_is_refused(): void
    {
        $cable = $this->product();

        Volt::test('pos.register')
            ->set('customer_id', $this->customer->id)
            ->call('addProduct', $cable->id)
            ->set('payments.0.amount', '600')
            ->call('checkout')
            ->assertHasErrors(['payments']);

        $this->assertSame(0, Sale::count());
    }

    public function test_a_sale_past_its_due_date_is_overdue_and_listed_as_such(): void
    {
        $cable = $this->product();
        $sale = $this->sell($cable, 1, '100', set: ['dueDate' => now()->addDays(5)->toDateString()]);

        $this->assertFalse($sale->fresh()->isOverdue());

        $this->travel(6)->days();

        $this->assertTrue($sale->fresh()->isOverdue());
        $this->assertSame('overdue', $sale->fresh()->load('payments')->paymentState());

        Volt::test('pos.sales')
            ->set('filter', 'overdue')
            ->assertSee($sale->sale_number);

        Volt::test('pos.sales')
            ->set('filter', 'all')
            ->assertSee($sale->sale_number);
    }

    // ── Repayments ─────────────────────────────────────────────────────

    public function test_a_repayment_reduces_the_balance_and_gets_its_own_receipt(): void
    {
        $sale = $this->sell($this->product(), 2, '400', set: ['dueDate' => now()->addDays(10)->toDateString()]);

        Volt::test('pos.show', ['sale' => $sale])
            ->call('togglePaymentForm')
            ->assertSet('payAmount', '600.00')
            ->set('payAmount', '250')
            ->call('receivePayment')
            ->assertHasNoErrors();

        $sale = $sale->fresh();

        $this->assertSame('650.00', (string) $sale->amount_paid);
        $this->assertSame('350.00', $sale->balanceDue());
        $this->assertSame('350.00', $this->balanceOf($this->customer));

        $second = SalePayment::orderByDesc('id')->firstOrFail();
        $this->assertSame('S-000001-P2', $second->receipt_number);
        $this->assertSame('250.00', (string) $second->amount);

        // The ledger credit refers to it.
        $this->assertTrue(CustomerLedgerEntry::where('reference_type', SalePayment::class)->where('reference_id', $second->id)->exists());

        // Settling the rest closes the sale.
        Volt::test('pos.show', ['sale' => $sale])
            ->call('togglePaymentForm')
            ->call('receivePayment')
            ->assertHasNoErrors();

        $sale = $sale->fresh()->load('payments');
        $this->assertFalse($sale->hasBalanceDue());
        $this->assertSame('paid', $sale->paymentState());
        $this->assertSame('0.00', $this->balanceOf($this->customer));
    }

    public function test_a_repayment_cannot_exceed_the_balance_or_be_taken_on_a_settled_sale(): void
    {
        $sale = $this->sell($this->product(), 1, '100', set: ['dueDate' => now()->addDays(3)->toDateString()]);

        Volt::test('pos.show', ['sale' => $sale])
            ->call('togglePaymentForm')
            ->set('payAmount', '401')
            ->call('receivePayment')
            ->assertHasErrors(['payment.0.amount']);

        Volt::test('pos.show', ['sale' => $sale])
            ->call('togglePaymentForm')
            ->set('payAmount', '0')
            ->call('receivePayment')
            ->assertHasErrors(['payment.0.amount']);

        $this->assertSame(1, SalePayment::count());

        $paid = $this->sell($this->product(['sku' => 'CAB-2']), 1);

        Volt::test('pos.show', ['sale' => $paid])
            ->set('payAmount', '10')
            ->call('receivePayment')
            ->assertHasErrors(['payment']);
    }

    public function test_a_bank_repayment_needs_a_bank_and_keeps_its_slip(): void
    {
        Storage::fake('local');
        $bank = $this->bank();
        $sale = $this->sell($this->product(), 1, '100', set: ['dueDate' => now()->addDays(3)->toDateString()]);

        $component = Volt::test('pos.show', ['sale' => $sale])
            ->call('togglePaymentForm')
            ->call('setPayMethod', 'bank')
            ->set('payAmount', '200');

        $component->call('receivePayment')->assertHasErrors(['payment.0.bank_id']);

        $component->set('payBankId', (string) $bank->id)
            ->set('payReference', 'TRX-9')
            ->set('payProof', UploadedFile::fake()->image('slip.jpg'))
            ->call('receivePayment')
            ->assertHasNoErrors();

        $payment = SalePayment::orderByDesc('id')->firstOrFail();

        $this->assertSame('bank', $payment->payment_mode);
        $this->assertSame($bank->id, $payment->bank_id);
        $this->assertSame('TRX-9', $payment->reference_number);
        $this->assertNotNull($payment->receiptProof());
    }

    public function test_each_payment_has_a_printable_receipt_showing_what_is_still_owed(): void
    {
        $this->withoutVite();
        $sale = $this->sell($this->product(), 2, '400', set: ['dueDate' => now()->addDays(10)->toDateString()]);

        Volt::test('pos.show', ['sale' => $sale])->call('togglePaymentForm')->set('payAmount', '250')->call('receivePayment');

        [$first, $second] = SalePayment::orderBy('id')->get()->all();

        $this->get("/s/{$this->shop->slug}/sales/{$sale->id}/payments/{$first->id}/receipt")
            ->assertOk()->assertSee('S-000001-P1')->assertSee('Rs. 600');   // balance when it was taken

        $this->get("/s/{$this->shop->slug}/sales/{$sale->id}/payments/{$second->id}/receipt")
            ->assertOk()->assertSee('S-000001-P2')->assertSee('Rs. 350');

        // A payment can't be read through a different sale's URL.
        $other = $this->sell($this->product(['sku' => 'CAB-2']), 1);
        $this->get("/s/{$this->shop->slug}/sales/{$other->id}/payments/{$first->id}/receipt")->assertNotFound();
    }

    // ── Returns ────────────────────────────────────────────────────────

    public function test_returning_a_serialized_unit_restocks_it_and_refunds_cash(): void
    {
        $phone = $this->phone('IMEI-1', 'IMEI-2');
        $unit = ProductSerial::where('serial_number', 'IMEI-1')->firstOrFail();

        $register = Volt::test('pos.register')->set('customer_id', $this->customer->id)->call('addProduct', $phone->id)->call('addSerial', $unit->id);
        $register->call('checkout')->assertHasNoErrors();
        $sale = Sale::firstOrFail();

        $this->assertSame('sold', $unit->fresh()->status);
        $this->assertSame(1, $phone->fresh()->availableQuantity());

        Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')
            ->set('returnQty.0', '1')
            ->call('submitReturn')
            ->assertHasNoErrors();

        $this->assertSame('in_stock', $unit->fresh()->status);
        $this->assertNull($unit->fresh()->sold_at);
        $this->assertSame(2, $phone->fresh()->availableQuantity());

        $sale = $sale->fresh()->load('payments');
        $this->assertSame('returned', $sale->paymentState());
        $this->assertSame('50000.00', (string) $sale->returned_total);
        $this->assertSame('50000.00', (string) $sale->refunded_total);

        $return = SaleReturn::firstOrFail();
        $this->assertSame('R-000001', $return->return_number);
        $this->assertSame('cash', $return->payment_mode);
        $this->assertSame('50000.00', (string) $return->refund_amount);

        // Sale debit, payment credit, return credit, cash refund debit — nets to nothing.
        $this->assertSame(4, CustomerLedgerEntry::where('customer_id', $this->customer->id)->count());
        $this->assertSame('0.00', $this->balanceOf($this->customer));
    }

    public function test_a_partial_return_restocks_the_batch_and_leaves_the_rest_sold(): void
    {
        $cable = $this->product(['stock_quantity' => 5]);
        $vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Supplier', 'phone' => '0300', 'is_active' => true]);
        $order = PurchaseOrder::create([
            'shop_id' => $this->shop->id, 'vendor_id' => $vendor->id, 'po_number' => 'PO-1',
            'order_date' => now(), 'status' => 'received', 'payment_mode' => 'cash',
        ]);
        $batch = PurchaseOrderItem::create([
            'shop_id' => $this->shop->id, 'purchase_order_id' => $order->id, 'product_id' => $cable->id,
            'quantity' => 5, 'cost_price' => '300', 'selling_cash_price' => '500', 'subtotal' => '1500',
        ]);

        $sale = $this->sell($cable, 3);

        $this->assertSame(2, $batch->fresh()->remainingQuantity());
        $this->assertSame(2, $cable->fresh()->availableQuantity());

        Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')
            ->set('returnQty.0', '2')
            ->call('submitReturn')
            ->assertHasNoErrors();

        $this->assertSame(4, $batch->fresh()->remainingQuantity());
        $this->assertSame(4, $cable->fresh()->availableQuantity());

        $sale = $sale->fresh()->load('payments');
        $this->assertSame('1000.00', (string) $sale->returned_total);
        $this->assertSame('paid', $sale->paymentState());     // 1 unit is still with the customer
        $this->assertNotSame('returned', $sale->status);
        $this->assertSame(1, (int) $sale->items()->sum('quantity') - (int) $sale->items()->sum('returned_quantity'));
    }

    public function test_a_return_is_worth_its_share_of_the_bill_discount(): void
    {
        $phone = $this->phone('IMEI-1', 'IMEI-2');
        $units = ProductSerial::orderBy('id')->get();

        $register = Volt::test('pos.register')->call('addSerial', $units[0]->id)->call('addSerial', $units[1]->id);
        $register->set('billDiscountType', 'percent')->set('billDiscountValue', '10')->call('checkout')->assertHasNoErrors();

        $sale = Sale::firstOrFail();
        $this->assertSame('90000.00', (string) $sale->total);

        // One of two Rs. 50,000 phones, after the 10% off the bill: Rs. 45,000.
        $component = Volt::test('pos.show', ['sale' => $sale])->call('toggleReturnForm')->set('returnQty.0', '1');

        $this->assertSame('45000.00', $component->get('returnQuote')['value']);
        $this->assertSame('45000.00', $component->get('returnQuote')['overpaid']);
    }

    public function test_cannot_return_more_than_was_sold_or_return_twice(): void
    {
        $sale = $this->sell($this->product(), 2);

        Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')
            ->set('returnQty.0', '5')                        // clamped to what can come back
            ->assertSet('returnQty.0', '2');

        // A crafted request bypassing the clamp is refused by the service itself.
        $row = $sale->items()->firstOrFail();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        (new \App\Support\PosReturns($this->shop, $this->cashier))->process($sale, [$row->id => 3], ['mode' => 'cash']);
    }

    public function test_a_returned_item_cannot_be_returned_again(): void
    {
        $sale = $this->sell($this->product(), 1);

        Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')->set('returnQty.0', '1')->call('submitReturn')->assertHasNoErrors();

        $row = $sale->items()->firstOrFail();

        $this->assertSame(0, $row->fresh()->returnableQuantity());
        $this->assertSame(1, SaleReturn::count());

        try {
            (new \App\Support\PosReturns($this->shop, $this->cashier))->process($sale->fresh(), [$row->id => 1], ['mode' => 'cash']);
            $this->fail('A second return of the same unit went through.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey("items.{$row->id}", $e->errors());
        }

        $this->assertSame(1, SaleReturn::count());
    }

    public function test_returning_goods_on_a_sale_with_a_balance_reduces_what_is_owed(): void
    {
        // Rs. 1,000 sale, Rs. 400 paid, Rs. 600 owed.
        $sale = $this->sell($this->product(), 2, '400', set: ['dueDate' => now()->addDays(10)->toDateString()]);

        Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')
            ->set('returnQty.0', '1')                        // worth Rs. 500
            ->call('submitReturn')
            ->assertHasNoErrors();                           // no refund to choose

        $sale = $sale->fresh()->load('payments');

        $this->assertSame('100.00', $sale->balanceDue());    // owed Rs. 500 for what was kept, Rs. 400 paid
        $this->assertSame('0.00', (string) $sale->refunded_total);
        $this->assertSame('100.00', $this->balanceOf($this->customer));

        $return = SaleReturn::firstOrFail();
        $this->assertNull($return->payment_mode);
        $this->assertSame('0.00', (string) $return->refund_amount);
    }

    public function test_returning_more_than_was_paid_refunds_the_excess_and_demands_a_choice(): void
    {
        // Rs. 1,000 sale, Rs. 400 paid; returning everything leaves Rs. 400 to give back.
        $sale = $this->sell($this->product(), 2, '400', set: ['dueDate' => now()->addDays(10)->toDateString()]);

        $component = Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')
            ->set('returnQty.0', '2');

        $this->assertSame('1000.00', $component->get('returnQuote')['value']);
        $this->assertSame('400.00', $component->get('returnQuote')['overpaid']);

        $component->set('refundMode', '')->call('submitReturn')->assertHasErrors(['refund.mode']);
        $this->assertSame(0, SaleReturn::count());

        $component->call('setRefundMode', 'cash')->call('submitReturn')->assertHasNoErrors();

        $sale = $sale->fresh()->load('payments');

        $this->assertSame('returned', $sale->paymentState());
        $this->assertSame('400.00', (string) $sale->refunded_total);
        $this->assertSame('0.00', $sale->balanceDue());
        $this->assertSame('0.00', $this->balanceOf($this->customer));
    }

    public function test_a_refund_can_be_left_as_credit_on_the_customers_ledger(): void
    {
        $sale = $this->sell($this->product(), 1);

        Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')
            ->set('returnQty.0', '1')
            ->call('setRefundMode', 'credit')
            ->call('submitReturn')
            ->assertHasNoErrors();

        $return = SaleReturn::firstOrFail();

        $this->assertSame('credit', $return->payment_mode);
        $this->assertSame('0.00', (string) $return->refund_amount);
        $this->assertSame('500.00', (string) $return->credit_amount);

        $sale = $sale->fresh();
        $this->assertSame('0.00', (string) $sale->refunded_total);
        $this->assertSame('-500.00', $this->balanceOf($this->customer));   // the shop owes them Rs. 500
    }

    public function test_a_walk_in_sale_cannot_be_refunded_to_a_ledger(): void
    {
        $cable = $this->product();

        Volt::test('pos.register')->call('addProduct', $cable->id)->call('checkout')->assertHasNoErrors();
        $sale = Sale::firstOrFail();

        $component = Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')
            ->set('returnQty.0', '1')
            ->call('setRefundMode', 'credit');                 // ignored — there is no ledger

        $component->assertSet('refundMode', 'cash');

        $component->call('submitReturn')->assertHasNoErrors();

        $this->assertSame(0, CustomerLedgerEntry::count());
        $this->assertSame('cash', SaleReturn::firstOrFail()->payment_mode);
    }

    public function test_a_bank_refund_needs_a_bank_and_can_carry_a_slip(): void
    {
        Storage::fake('local');
        $bank = $this->bank();
        $sale = $this->sell($this->product(), 1);

        $component = Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')
            ->set('returnQty.0', '1')
            ->call('setRefundMode', 'bank');

        $component->call('submitReturn')->assertHasErrors(['refund.bank_id']);

        $component->set('refundBankId', (string) $bank->id)
            ->set('refundProof', UploadedFile::fake()->image('refund.jpg'))
            ->call('submitReturn')
            ->assertHasNoErrors();

        $return = SaleReturn::firstOrFail();

        $this->assertSame('bank', $return->payment_mode);
        $this->assertSame($bank->id, $return->bank_id);
        $this->assertNotNull($return->receiptProof());
    }

    public function test_piecemeal_returns_add_up_to_exactly_the_sale_total(): void
    {
        // 3 × 333.33 = 999.99, less 10% = 899.99 — which does not divide by three.
        $product = $this->product(['cash_price' => '333.33']);
        $sale = $this->sell($product, 3, set: ['billDiscountType' => 'percent', 'billDiscountValue' => '10']);

        $this->assertSame('899.99', (string) $sale->total);

        foreach ([1, 2, 3] as $step) {
            Volt::test('pos.show', ['sale' => $sale->fresh()])
                ->call('toggleReturnForm')
                ->set('returnQty.0', '1')
                ->call('submitReturn')
                ->assertHasNoErrors();
        }

        $sale = $sale->fresh();

        $this->assertSame('899.99', (string) $sale->returned_total);
        $this->assertSame('returned', $sale->status);
        $this->assertEqualsWithDelta(899.99, (float) SaleReturn::sum('total_value'), 0.001);
        $this->assertSame('0.00', $this->balanceOf($this->customer));
    }

    // ── Customer portal ────────────────────────────────────────────────

    public function test_a_customer_sees_their_own_purchases_and_balance_due_only(): void
    {
        $this->withoutVite();

        $mine = $this->sell($this->product(['name' => 'My Charger']), 2, '400', set: ['dueDate' => now()->addDays(10)->toDateString()]);

        $neighbour = $this->customer('22222-2222222-2');
        $theirs = $this->sell($this->product(['name' => 'Neighbours Speaker', 'sku' => 'SPK-1']), 1, null, $neighbour);

        $walkIn = Sale::create([
            'shop_id' => $this->shop->id, 'sequence' => 99, 'sale_number' => 'S-000099', 'status' => 'completed',
            'subtotal' => '10', 'total' => '10', 'amount_paid' => '10', 'sold_at' => now(),
        ]);

        $this->asCustomer($this->customer);

        $this->get("/{$this->shop->slug}/customer/purchases")
            ->assertOk()
            ->assertSee('My Charger')
            ->assertSee('Rs. 600')                           // balance due
            ->assertDontSee('Neighbours Speaker')
            ->assertDontSee('S-000099');

        $this->get("/{$this->shop->slug}/customer/purchases/{$mine->id}")
            ->assertOk()->assertSee('My Charger')->assertSee('S-000001-P1');

        $this->get("/{$this->shop->slug}/customer/purchases/{$theirs->id}")->assertForbidden();
        $this->get("/{$this->shop->slug}/customer/purchases/{$walkIn->id}")->assertForbidden();
    }

    public function test_a_customer_can_open_their_receipts_but_not_anyone_elses(): void
    {
        $this->withoutVite();

        $mine = $this->sell($this->product(), 1);
        $neighbour = $this->customer('22222-2222222-2');
        $theirs = $this->sell($this->product(['sku' => 'CAB-2']), 1, null, $neighbour);

        $this->asCustomer($this->customer);

        $myPayment = $mine->payments()->firstOrFail();
        $theirPayment = $theirs->payments()->firstOrFail();

        $this->get("/{$this->shop->slug}/customer/purchases/{$mine->id}/receipt")->assertOk()->assertSee('S-000001');
        $this->get("/{$this->shop->slug}/customer/purchases/{$mine->id}/payments/{$myPayment->id}/receipt")->assertOk();

        $this->get("/{$this->shop->slug}/customer/purchases/{$theirs->id}/receipt")->assertForbidden();
        $this->get("/{$this->shop->slug}/customer/purchases/{$theirs->id}/payments/{$theirPayment->id}/receipt")->assertForbidden();

        // …and not by dressing one sale's URL with another's payment.
        $this->get("/{$this->shop->slug}/customer/purchases/{$mine->id}/payments/{$theirPayment->id}/receipt")->assertNotFound();
    }

    public function test_staff_screens_and_portal_agree_after_a_return(): void
    {
        $this->withoutVite();
        $sale = $this->sell($this->product(['name' => 'Kettle']), 2);

        Volt::test('pos.show', ['sale' => $sale])
            ->call('toggleReturnForm')->set('returnQty.0', '1')->call('submitReturn')->assertHasNoErrors();

        $this->get("/s/{$this->shop->slug}/sales/{$sale->id}")
            ->assertOk()->assertSee('R-000001')->assertSee('1 returned');

        $this->asCustomer($this->customer);

        $this->get("/{$this->shop->slug}/customer/purchases/{$sale->id}")
            ->assertOk()->assertSee('R-000001')->assertSee('1 returned');
    }
}
