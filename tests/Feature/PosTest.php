<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Support\PosCalculator;
use App\Support\Tenant;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $cashier;

    private function bootShop(bool $pos = true): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->shop = Shop::create([
            'name' => 'POS Shop', 'slug' => 'pos-shop', 'is_active' => true, 'pos_enabled' => $pos,
            'address' => '1 Market Rd', 'phone' => '021-111',
        ]);

        $this->cashier = $this->staff($this->shop, 'Salesman');

        $this->signIn($this->shop, $this->cashier);
    }

    private function staff(Shop $shop, string $role): User
    {
        $user = User::factory()->create(['shop_id' => $shop->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function signIn(Shop $shop, User $user): void
    {
        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);
        $this->actingAs($user);
    }

    private function product(array $overrides = [], ?Shop $shop = null): Product
    {
        return Product::create(array_merge([
            'shop_id' => ($shop ?? $this->shop)->id,
            'name' => 'Cable',
            'category' => 'other',
            'sku' => 'CAB-1',
            'cash_price' => '500',
            'cost_price' => '300',
            'is_serialized' => false,
            'stock_quantity' => 10,
            'reorder_level' => 2,
            'is_active' => true,
        ], $overrides));
    }

    private function phone(string ...$imeis): Product
    {
        $product = $this->product([
            'name' => 'Phone X', 'sku' => 'PHN-X', 'category' => 'mobile',
            'cash_price' => '50000', 'cost_price' => '42000', 'is_serialized' => true, 'stock_quantity' => count($imeis),
        ]);

        foreach ($imeis as $imei) {
            ProductSerial::create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'serial_number' => $imei, 'status' => 'in_stock']);
        }

        return $product;
    }

    private function customer(?Shop $shop = null, string $cnic = '11111-1111111-1'): Customer
    {
        return Customer::create([
            'shop_id' => ($shop ?? $this->shop)->id, 'first_name' => 'Jane', 'last_name' => 'Doe',
            'cnic_number' => $cnic, 'phone' => '03001234567', 'is_active' => true,
        ]);
    }

    // ── Availability ───────────────────────────────────────────────────

    public function test_pos_is_unavailable_until_it_is_switched_on_for_the_shop(): void
    {
        $this->bootShop(pos: false);
        $this->withoutVite();

        $this->get("/s/{$this->shop->slug}/pos")->assertNotFound();
        $this->get("/s/{$this->shop->slug}/sales")->assertNotFound();

        $this->shop->update(['pos_enabled' => true]);

        $this->get("/s/{$this->shop->slug}/pos")->assertOk();
        $this->get("/s/{$this->shop->slug}/sales")->assertOk();
    }

    public function test_checkout_is_refused_for_a_shop_without_pos_even_via_a_component_action(): void
    {
        $this->bootShop();
        $cable = $this->product();

        $component = Volt::test('pos.register')->call('addProduct', $cable->id);

        // Switched off between page load and the click.
        $this->shop->update(['pos_enabled' => false]);
        Tenant::set($this->shop->fresh());

        $component->call('checkout')->assertNotFound();

        $this->assertSame(0, Sale::count());
    }

    // ── Selling ────────────────────────────────────────────────────────

    public function test_cash_sale_of_a_plain_product_reduces_stock_and_numbers_sequentially(): void
    {
        $this->bootShop();
        $cable = $this->product();

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->call('addProduct', $cable->id)
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertRedirect();

        $sale = Sale::firstOrFail();

        $this->assertSame('S-000001', $sale->sale_number);
        $this->assertSame('1000.00', (string) $sale->total);
        $this->assertSame('1000.00', (string) $sale->amount_paid);
        $this->assertNull($sale->customer_id);
        $this->assertSame($this->cashier->id, $sale->sold_by);
        $this->assertSame(8, $cable->fresh()->availableQuantity());

        $payment = $sale->payments()->firstOrFail();
        $this->assertSame('cash', $payment->payment_mode);
        $this->assertSame('1000.00', (string) $payment->amount);

        Volt::test('pos.register')->call('addProduct', $cable->id)->call('checkout')->assertHasNoErrors();

        $this->assertSame('S-000002', Sale::orderByDesc('id')->first()->sale_number);
        $this->assertSame(7, $cable->fresh()->availableQuantity());
    }

    public function test_serialized_unit_is_chosen_and_marked_sold(): void
    {
        $this->bootShop();
        $phone = $this->phone('IMEI-1', 'IMEI-2');
        $second = ProductSerial::where('serial_number', 'IMEI-2')->firstOrFail();

        Volt::test('pos.register')
            ->call('addProduct', $phone->id)
            ->assertSet('pickingProductId', $phone->id)
            ->call('addSerial', $second->id)
            ->assertSet('pickingProductId', null)
            ->call('checkout')
            ->assertHasNoErrors();

        $this->assertSame('sold', $second->fresh()->status);
        $this->assertNotNull($second->fresh()->sold_at);
        $this->assertSame('in_stock', ProductSerial::where('serial_number', 'IMEI-1')->value('status'));
        $this->assertSame(1, $phone->fresh()->availableQuantity());

        $item = SaleItem::firstOrFail();
        $this->assertSame('IMEI-2', $item->serial_number);
        $this->assertSame('Phone X', $item->product_name);
        $this->assertSame('42000.00', (string) $item->cost_price);
    }

    public function test_the_last_remaining_unit_is_still_asked_about(): void
    {
        $this->bootShop();
        $phone = $this->phone('ONLY-ONE');
        $unit = ProductSerial::where('serial_number', 'ONLY-ONE')->firstOrFail();

        // The cashier confirms the unit even when there is nothing to choose between.
        Volt::test('pos.register')
            ->call('addProduct', $phone->id)
            ->assertSet('pickingProductId', $phone->id)
            ->assertCount('cart', 0)
            ->call('addSerial', $unit->id)
            ->assertSet('pickingProductId', null)
            ->assertCount('cart', 1);
    }

    public function test_scanning_a_serial_or_sku_adds_it_and_unknown_codes_become_a_search(): void
    {
        $this->bootShop();
        $this->phone('IMEI-9', 'IMEI-8');
        $this->product();

        Volt::test('pos.register')
            ->set('scan', 'IMEI-9')->call('submitScan')
            ->assertCount('cart', 1)
            ->set('scan', 'CAB-1')->call('submitScan')
            ->assertCount('cart', 2)
            ->set('scan', 'nothing-like-this')->call('submitScan')
            ->assertCount('cart', 2)
            ->assertSet('search', 'nothing-like-this');
    }

    public function test_a_sold_serial_cannot_be_sold_again(): void
    {
        $this->bootShop();
        $phone = $this->phone('IMEI-1', 'IMEI-2');
        $unit = ProductSerial::where('serial_number', 'IMEI-1')->firstOrFail();
        $unit->update(['status' => 'sold']);

        Volt::test('pos.register')
            ->set('scan', 'IMEI-1')->call('submitScan')
            ->assertCount('cart', 0)
            ->assertSet('noticeIsError', true);

        // And a stale cart that still holds it is refused at checkout.
        Volt::test('pos.register')
            ->set('cart', [['product_id' => $phone->id, 'product_serial_id' => $unit->id, 'quantity' => 1, 'discount_type' => 'fixed', 'discount_value' => '']])
            ->call('checkout')
            ->assertHasErrors(['cart.0.product_serial_id']);

        $this->assertSame(0, Sale::count());
    }

    public function test_quantity_cannot_exceed_stock(): void
    {
        $this->bootShop();
        $cable = $this->product(['stock_quantity' => 3]);

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->set('cart.0.quantity', 99)
            ->assertSet('cart.0.quantity', 3);

        // A tampered cart is stopped by the checkout itself.
        Volt::test('pos.register')
            ->set('cart', [['product_id' => $cable->id, 'product_serial_id' => null, 'quantity' => 4, 'discount_type' => 'fixed', 'discount_value' => '']])
            ->call('checkout')
            ->assertHasErrors(['cart']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(3, $cable->fresh()->availableQuantity());
    }

    public function test_stock_counts_what_agreements_have_already_taken(): void
    {
        $this->bootShop();
        $cable = $this->product(['stock_quantity' => 5]);

        $agreement = Agreement::create([
            'shop_id' => $this->shop->id, 'customer_id' => $this->customer()->id,
            'agreement_number' => 'AGR-1', 'status' => 'active',
            'product_price' => '1000', 'down_payment' => '0', 'processing_fee' => '0', 'interest_rate' => '0',
            'duration_months' => 1, 'financed_amount' => '1000', 'total_interest' => '0', 'total_payable' => '1000',
            'monthly_installment' => '1000', 'start_date' => now(), 'first_due_date' => now(),
        ]);
        AgreementItem::create(['shop_id' => $this->shop->id, 'agreement_id' => $agreement->id, 'product_id' => $cable->id, 'quantity' => 2, 'unit_price' => '500']);

        $this->assertSame(3, $cable->availableQuantity());
        $this->assertSame(3, Product::withAvailableQuantity()->findOrFail($cable->id)->availableQuantity());
    }

    // ── Totals ─────────────────────────────────────────────────────────

    public function test_line_and_bill_discounts_are_applied_and_stored(): void
    {
        $this->bootShop();
        $cable = $this->product();

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->call('addProduct', $cable->id)                       // 2 × 500 = 1000
            ->set('cart.0.discount_type', 'fixed')
            ->set('cart.0.discount_value', '100')                  // → 900
            ->set('billDiscountType', 'percent')
            ->set('billDiscountValue', '10')                       // → 810
            ->call('checkout')
            ->assertHasNoErrors();

        $sale = Sale::firstOrFail();

        $this->assertSame('1000.00', (string) $sale->subtotal);
        $this->assertSame('100.00', (string) $sale->line_discount_total);
        $this->assertSame('90.00', (string) $sale->bill_discount_amount);
        $this->assertSame('810.00', (string) $sale->total);
        $this->assertSame('810.00', (string) $sale->amount_paid);
    }

    public function test_calculator_rounds_clamps_and_ignores_junk(): void
    {
        $this->assertSame('74.93', PosCalculator::discount('999.00', 'percent', '7.5'));   // 74.925 → half up
        $this->assertSame('500.00', PosCalculator::discount('500.00', 'fixed', '9999'));    // never more than the base
        $this->assertSame('500.00', PosCalculator::discount('500.00', 'percent', '150'));
        $this->assertSame('0.00', PosCalculator::discount('500.00', 'fixed', '-20'));
        $this->assertSame('0.00', PosCalculator::discount('500.00', 'fixed', 'abc'));
        $this->assertSame('0.00', PosCalculator::discount('500.00', 'fixed', '1e5'));

        $this->assertSame('Rs. 1,250', PosCalculator::money('1250.00'));
        $this->assertSame('Rs. 1,249.50', PosCalculator::money('1249.50'));
        $this->assertSame('-Rs. 40', PosCalculator::money('-40.00'));
    }

    public function test_invalid_discounts_are_rejected(): void
    {
        $this->bootShop();
        $cable = $this->product();

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->set('cart.0.discount_type', 'percent')
            ->set('cart.0.discount_value', '120')
            ->call('checkout')
            ->assertHasErrors(['cart.0.discount_value']);

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->set('billDiscountType', 'fixed')
            ->set('billDiscountValue', '-5')
            ->call('checkout')
            ->assertHasErrors(['billDiscountValue']);

        $this->assertSame(0, Sale::count());
    }

    // ── Payments ───────────────────────────────────────────────────────

    public function test_payments_must_add_up_to_the_total(): void
    {
        $this->bootShop();
        $cable = $this->product();

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->set('payments.0.amount', '100')
            ->call('checkout')
            ->assertHasErrors(['payments']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(10, $cable->fresh()->availableQuantity());
    }

    public function test_split_payment_between_cash_and_a_bank(): void
    {
        $this->bootShop();
        $cable = $this->product();
        $bank = Bank::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'is_active' => true]);

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->call('addProduct', $cable->id)                       // 1000
            ->set('payments.0.amount', '400')
            ->set('payments.0.tendered', '500')
            ->call('addPayment')
            ->call('setPaymentMethod', 1, 'bank')
            ->set('payments.1.bank_id', (string) $bank->id)
            ->set('payments.1.reference', 'TRX-77')                // amount left blank → takes the balance (600)
            ->call('checkout')
            ->assertHasNoErrors();

        $payments = SalePayment::orderBy('id')->get();

        $this->assertCount(2, $payments);
        $this->assertSame('cash', $payments[0]->payment_mode);
        $this->assertSame('400.00', (string) $payments[0]->amount);
        $this->assertSame('100.00', $payments[0]->changeGiven());
        $this->assertSame('bank', $payments[1]->payment_mode);
        $this->assertSame($bank->id, $payments[1]->bank_id);
        $this->assertSame('600.00', (string) $payments[1]->amount);
        $this->assertSame('TRX-77', $payments[1]->reference_number);
    }

    public function test_a_bank_slip_is_attached_to_the_bank_payment_and_never_to_cash(): void
    {
        $this->bootShop();
        Storage::fake('local');
        $cable = $this->product();
        $bank = Bank::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'is_active' => true]);

        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->call('setPaymentMethod', 0, 'bank')
            ->set('payments.0.bank_id', (string) $bank->id)
            ->set('proofs.0', UploadedFile::fake()->image('slip.jpg'))
            ->call('checkout')
            ->assertHasNoErrors();

        $payment = SalePayment::firstOrFail();

        $this->assertNotNull($payment->receiptProof());
        $this->assertFalse($payment->missingReceiptProof());

        // A slip left over from a moment when Bank was selected is dropped for cash.
        Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->call('setPaymentMethod', 0, 'bank')
            ->set('proofs.0', UploadedFile::fake()->image('stale.jpg'))
            ->call('setPaymentMethod', 0, 'cash')
            ->call('checkout')
            ->assertHasNoErrors();

        $cash = SalePayment::orderByDesc('id')->firstOrFail();

        $this->assertSame('cash', $cash->payment_mode);
        $this->assertNull($cash->receiptProof());
    }

    public function test_a_bank_payment_needs_one_of_this_shops_banks(): void
    {
        $this->bootShop();
        $cable = $this->product();
        Bank::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'is_active' => true]);

        $other = Shop::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
        $foreignBank = Bank::create(['shop_id' => $other->id, 'name' => 'Foreign', 'is_active' => true]);

        $component = Volt::test('pos.register')
            ->call('addProduct', $cable->id)
            ->call('setPaymentMethod', 0, 'bank');

        $component->call('checkout')->assertHasErrors(['payments.0.bank_id']);

        $component->set('payments.0.bank_id', (string) $foreignBank->id)
            ->call('checkout')
            ->assertHasErrors(['payments.0.bank_id']);

        $this->assertSame(0, Sale::count());
    }

    // ── Customers & ledger ─────────────────────────────────────────────

    public function test_a_registered_customers_sale_is_posted_to_their_ledger(): void
    {
        $this->bootShop();
        $cable = $this->product();
        $customer = $this->customer();

        Volt::test('pos.register')
            ->set('customer_id', $customer->id)
            ->call('addProduct', $cable->id)
            ->call('checkout')
            ->assertHasNoErrors();

        $sale = Sale::firstOrFail();
        $this->assertSame($customer->id, $sale->customer_id);

        $entries = CustomerLedgerEntry::where('customer_id', $customer->id)->orderBy('id')->get();

        $this->assertCount(2, $entries);
        $this->assertSame('debit', $entries[0]->type);
        $this->assertSame(Sale::class, $entries[0]->reference_type);
        $this->assertSame('500.00', (string) $entries[0]->amount);
        $this->assertSame('credit', $entries[1]->type);
        $this->assertSame(SalePayment::class, $entries[1]->reference_type);
        $this->assertSame('cash', $entries[1]->payment_mode);
        $this->assertSame('0.00', (string) $entries[1]->running_balance);
        $this->assertFalse($entries[0]->isManual());
    }

    public function test_a_walk_in_sale_leaves_the_ledger_alone_and_keeps_the_typed_name(): void
    {
        $this->bootShop();
        $cable = $this->product();

        Volt::test('pos.register')
            ->set('walkInName', 'Ali')
            ->set('walkInPhone', '0300-5550000')
            ->call('addProduct', $cable->id)
            ->call('checkout')
            ->assertHasNoErrors();

        $sale = Sale::firstOrFail();

        $this->assertTrue($sale->isWalkIn());
        $this->assertSame('Ali', $sale->customerName());
        $this->assertSame('0300-5550000', $sale->customerPhone());
        $this->assertSame(0, CustomerLedgerEntry::count());
        $this->assertStringContainsString('wa.me/923005550000', $sale->whatsappUrl());
    }

    // ── Tenant isolation ───────────────────────────────────────────────

    public function test_another_shops_customer_and_product_cannot_be_used(): void
    {
        $this->bootShop();
        $other = Shop::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true, 'pos_enabled' => true]);
        $foreignCustomer = $this->customer($other, '99999-9999999-9');
        $foreignProduct = $this->product(['sku' => 'F-1', 'name' => 'Foreign Thing'], $other);
        $mine = $this->product();

        Volt::test('pos.register')
            ->call('addProduct', $foreignProduct->id)
            ->assertCount('cart', 0)
            ->assertSet('noticeIsError', true);

        Volt::test('pos.register')
            ->set('customer_id', $foreignCustomer->id)
            ->call('addProduct', $mine->id)
            ->call('checkout')
            ->assertHasErrors(['customer_id']);

        Volt::test('pos.register')
            ->set('cart', [['product_id' => $foreignProduct->id, 'product_serial_id' => null, 'quantity' => 1, 'discount_type' => 'fixed', 'discount_value' => '']])
            ->call('checkout')
            ->assertHasErrors(['cart.0.product_id']);

        $this->assertSame(0, Sale::count());
    }

    // ── Batches ────────────────────────────────────────────────────────

    public function test_non_serialized_stock_is_drawn_from_the_oldest_batch_first(): void
    {
        $this->bootShop();
        $cable = $this->product(['stock_quantity' => 8, 'cost_price' => '999']);
        $vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Supplier', 'phone' => '0300', 'is_active' => true]);

        $order = PurchaseOrder::create([
            'shop_id' => $this->shop->id, 'vendor_id' => $vendor->id, 'po_number' => 'PO-1',
            'order_date' => now(), 'status' => 'received', 'payment_mode' => 'cash',
        ]);

        $first = PurchaseOrderItem::create(['shop_id' => $this->shop->id, 'purchase_order_id' => $order->id, 'product_id' => $cable->id, 'quantity' => 3, 'cost_price' => '100', 'selling_cash_price' => '500', 'subtotal' => '300']);
        $second = PurchaseOrderItem::create(['shop_id' => $this->shop->id, 'purchase_order_id' => $order->id, 'product_id' => $cable->id, 'quantity' => 5, 'cost_price' => '120', 'selling_cash_price' => '500', 'subtotal' => '600']);

        Volt::test('pos.register')
            ->set('cart', [['product_id' => $cable->id, 'product_serial_id' => null, 'quantity' => 4, 'discount_type' => 'fixed', 'discount_value' => '100']])
            ->call('checkout')
            ->assertHasNoErrors();

        $items = SaleItem::orderBy('id')->get();

        $this->assertCount(2, $items);
        $this->assertSame([$first->id, 3, '100.00'], [$items[0]->purchase_order_item_id, $items[0]->quantity, (string) $items[0]->cost_price]);
        $this->assertSame([$second->id, 1, '120.00'], [$items[1]->purchase_order_item_id, $items[1]->quantity, (string) $items[1]->cost_price]);

        // The line's Rs. 100 discount is split between the pieces and adds back up.
        $this->assertSame('100.00', bcadd((string) $items[0]->discount_amount, (string) $items[1]->discount_amount, 2));
        $this->assertSame('1900.00', bcadd((string) $items[0]->line_total, (string) $items[1]->line_total, 2));
        $this->assertSame('1900.00', (string) Sale::firstOrFail()->total);

        $this->assertSame(0, $first->fresh()->remainingQuantity());
        $this->assertSame(4, $second->fresh()->remainingQuantity());
        $this->assertSame(4, $cable->fresh()->availableQuantity());

        // On paper it is still one line.
        $this->assertCount(1, Sale::firstOrFail()->load('items')->displayLines());
    }

    // ── Documents ──────────────────────────────────────────────────────

    public function test_receipt_invoice_and_detail_page_render_for_the_shops_staff_only(): void
    {
        $this->bootShop();
        $this->withoutVite();
        $cable = $this->product(['name' => 'Charging Cable']);

        Volt::test('pos.register')->call('addProduct', $cable->id)->call('checkout');
        $sale = Sale::firstOrFail();

        $this->get("/s/{$this->shop->slug}/sales/{$sale->id}/receipt")
            ->assertOk()->assertSee('S-000001')->assertSee('Charging Cable')->assertSee('POS Shop');

        $this->get("/s/{$this->shop->slug}/sales/{$sale->id}/invoice")
            ->assertOk()->assertSee('S-000001')->assertSee('Charging Cable');

        $this->get("/s/{$this->shop->slug}/sales/{$sale->id}")
            ->assertOk()->assertSee('S-000001');

        $this->get("/s/{$this->shop->slug}/sales")->assertOk()->assertSee('S-000001');

        $other = Shop::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true, 'pos_enabled' => true]);
        $outsider = $this->staff($other, 'Shop Admin');

        $this->signIn($other, $outsider);

        $this->get("/s/{$other->slug}/sales/{$sale->id}/receipt")->assertNotFound();
        $this->get("/s/{$other->slug}/sales/{$sale->id}/invoice")->assertNotFound();
    }

    // ── Super Admin switch ─────────────────────────────────────────────

    public function test_super_admin_can_switch_pos_on_for_a_shop(): void
    {
        $this->bootShop(pos: false);

        $admin = User::factory()->create(['shop_id' => null, 'is_active' => true]);
        $admin->assignRole('Super Admin');
        $this->actingAs($admin);

        // fresh() so the DB defaults (grace period etc.) are loaded, as they
        // would be for a shop read back from the database.
        Volt::test('superadmin.shops.form', ['shop' => $this->shop->fresh()])
            ->assertSet('pos_enabled', false)
            ->set('pos_enabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($this->shop->fresh()->pos_enabled);
    }
}
