<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Support\PosCheckout;
use App\Support\Tenant;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * What an agreement does to stock: units leave the shelf when it is created
 * (and a product with nothing left can't be sold), and come back when it is
 * cancelled.
 */
class AgreementStockTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $admin;

    private Customer $customer;

    private function bootShop(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->shop = Shop::create([
            'name' => 'Stock Shop', 'slug' => 'stock-shop', 'is_active' => true, 'pos_enabled' => true,
            'address' => '1 Market Rd', 'phone' => '021-111',
        ]);

        $this->admin = $this->staff('Shop Admin');

        Tenant::set($this->shop);
        URL::defaults(['shop' => $this->shop->slug]);
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'shop_id' => $this->shop->id, 'first_name' => 'Jane', 'last_name' => 'Doe',
            'cnic_number' => '11111-1111111-1', 'phone' => '03001234567', 'is_active' => true,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['shop_id' => $this->shop->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function cable(int $stock, string $sku = 'CAB-1'): Product
    {
        return Product::create([
            'shop_id' => $this->shop->id, 'name' => 'Cable', 'category' => 'other', 'sku' => $sku,
            'cash_price' => '1000', 'cost_price' => '700', 'is_serialized' => false,
            'stock_quantity' => $stock, 'reorder_level' => 1, 'is_active' => true,
        ]);
    }

    private function phone(string ...$imeis): Product
    {
        $product = Product::create([
            'shop_id' => $this->shop->id, 'name' => 'Phone X', 'category' => 'mobile', 'sku' => 'PHN-X',
            'cash_price' => '1000', 'cost_price' => '700', 'is_serialized' => true,
            'stock_quantity' => count($imeis), 'reorder_level' => 1, 'is_active' => true,
        ]);

        foreach ($imeis as $imei) {
            ProductSerial::create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'serial_number' => $imei, 'status' => 'in_stock']);
        }

        return $product;
    }

    private function batch(Product $product, int $quantity): PurchaseOrderItem
    {
        $vendor = Vendor::firstOrCreate(
            ['shop_id' => $this->shop->id, 'name' => 'Supplier'],
            ['phone' => '0300', 'is_active' => true],
        );

        $order = PurchaseOrder::create([
            'shop_id' => $this->shop->id, 'vendor_id' => $vendor->id, 'po_number' => 'PO-'.random_int(1000, 9999),
            'order_date' => now(), 'status' => 'received', 'payment_mode' => 'cash',
        ]);

        return PurchaseOrderItem::create([
            'shop_id' => $this->shop->id, 'purchase_order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => $quantity, 'cost_price' => '700', 'selling_cash_price' => '1000', 'subtotal' => (string) ($quantity * 700),
        ]);
    }

    /**
     * The real New Agreement form, filled the way the browser does it — one
     * field at a time. `$lines` is a list of [product, serial id|null, batch id|null].
     *
     * @param  array<int, array{0: Product, 1?: ?int, 2?: ?int}>  $lines
     */
    private function form(array $lines): Testable
    {
        $form = Volt::test('agreements.create')
            ->set('customer_id', $this->customer->id)
            ->set('salesman_id', $this->admin->id);

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $form->call('addItem');
            }

            $form->set("items.{$index}.product_id", $line[0]->id);

            if (! empty($line[1])) {
                $form->set("items.{$index}.product_serial_id", $line[1]);
            }

            if (! empty($line[2])) {
                $form->set("items.{$index}.purchase_order_item_id", $line[2]);
            }

            $form->set("items.{$index}.unit_price", '1000');
        }

        return $form
            ->set('productPrice', (string) (1000 * count($lines)))
            ->set('downPaymentValue', '200')
            ->set('durationMonths', 4)
            ->set('guarantor1_name', 'G One')
            ->set('guarantor1_cnic', '22222-2222222-2')
            ->set('guarantor1_mobile', '0311')
            ->set('guarantor1_relation', 'Brother');
    }

    /** Sells through the form and returns the agreement it created. */
    private function sell(array $lines): Agreement
    {
        $this->form($lines)->call('save')->assertHasNoErrors();

        return Agreement::latest('id')->firstOrFail();
    }

    private function cancel(Agreement $agreement): void
    {
        Volt::test('agreements.show', ['agreement' => $agreement])->call('cancel');
    }

    /** An agreement row with one item, without going through the form. */
    private function agreementWith(Product $product, ?ProductSerial $serial, string $status): Agreement
    {
        $agreement = Agreement::create([
            'shop_id' => $this->shop->id, 'customer_id' => $this->customer->id,
            'agreement_number' => 'AGR-'.random_int(10000, 99999), 'status' => $status,
            'product_price' => '1000', 'down_payment' => '0', 'processing_fee' => '0', 'interest_rate' => '0',
            'duration_months' => 1, 'financed_amount' => '1000', 'total_interest' => '0', 'total_payable' => '1000',
            'monthly_installment' => '1000', 'start_date' => now(), 'first_due_date' => now(),
        ]);

        AgreementItem::create([
            'shop_id' => $this->shop->id, 'agreement_id' => $agreement->id, 'product_id' => $product->id,
            'product_serial_id' => $serial?->id, 'quantity' => 1, 'unit_price' => '1000',
        ]);

        return $agreement;
    }

    // ── Selling ────────────────────────────────────────────────────────

    public function test_a_non_serialized_unit_on_an_agreement_comes_off_available_stock(): void
    {
        $this->bootShop();
        $cable = $this->cable(5);

        $this->sell([[$cable]]);

        $this->assertSame(1, AgreementItem::where('product_id', $cable->id)->count());
        $this->assertSame(4, $cable->fresh()->availableQuantity());
        $this->assertSame(4, Product::withAvailableQuantity()->findOrFail($cable->id)->availableQuantity());
    }

    public function test_an_agreement_cannot_take_a_product_that_is_out_of_stock(): void
    {
        $this->bootShop();
        $cable = $this->cable(0);

        // The form says so as soon as the product is picked…
        $form = $this->form([[$cable]])->assertSee('Cable is out of stock');

        // …and refuses to save it regardless.
        $form->call('save')->assertHasErrors(['items.0.product_id']);

        $this->assertSame(0, Agreement::count());
        $this->assertSame(0, AgreementItem::count());
        $this->assertSame(0, CustomerLedgerEntry::count());
    }

    public function test_an_agreement_cannot_take_more_than_is_left_across_all_its_lines(): void
    {
        $this->bootShop();
        $cable = $this->cable(1);

        $this->form([[$cable], [$cable]])->call('save')->assertHasErrors(['items.0.product_id']);

        $this->assertSame(0, Agreement::count());
        $this->assertSame(1, $cable->fresh()->availableQuantity());
    }

    public function test_an_agreement_can_take_exactly_what_is_left(): void
    {
        $this->bootShop();
        $cable = $this->cable(2);

        $this->sell([[$cable], [$cable]]);

        $this->assertSame(2, AgreementItem::count());
        $this->assertSame(0, $cable->fresh()->availableQuantity());
    }

    public function test_what_other_agreements_already_took_counts_against_a_new_one(): void
    {
        $this->bootShop();
        $cable = $this->cable(2);

        $this->sell([[$cable], [$cable]]);

        $this->form([[$cable]])->call('save')->assertHasErrors(['items.0.product_id']);

        $this->assertSame(1, Agreement::count());
    }

    public function test_serialized_units_are_still_picked_one_by_one(): void
    {
        $this->bootShop();
        $phone = $this->phone('IMEI-1', 'IMEI-2');
        $unit = $phone->serials()->orderBy('id')->first();

        $this->sell([[$phone, $unit->id]]);

        $this->assertSame('sold', $unit->fresh()->status);
        $this->assertSame(1, $phone->fresh()->availableQuantity());
    }

    // ── Cancelling ─────────────────────────────────────────────────────

    public function test_cancelling_gives_non_serialized_stock_back(): void
    {
        $this->bootShop();
        $cable = $this->cable(3);
        $batch = $this->batch($cable, 3);

        $agreement = $this->sell([[$cable, null, $batch->id]]);

        $this->assertSame(2, $cable->fresh()->availableQuantity());
        $this->assertSame(2, $batch->fresh()->remainingQuantity());

        $this->cancel($agreement);

        $this->assertSame('cancelled', $agreement->fresh()->status);
        $this->assertSame(3, $cable->fresh()->availableQuantity());
        $this->assertSame(3, $batch->fresh()->remainingQuantity());
    }

    public function test_stock_freed_by_a_cancelled_agreement_can_be_sold_again(): void
    {
        $this->bootShop();
        $cable = $this->cable(1);

        $first = $this->sell([[$cable]]);
        $this->form([[$cable]])->call('save')->assertHasErrors(['items.0.product_id']);

        $this->cancel($first);

        $second = $this->sell([[$cable]]);

        $this->assertSame('pending_approval', $second->status);
        $this->assertSame(0, $cable->fresh()->availableQuantity());
    }

    public function test_cancelling_puts_a_serial_unit_back_in_stock_and_it_can_be_sold_again(): void
    {
        $this->bootShop();
        $phone = $this->phone('IMEI-1');
        $unit = $phone->serials()->firstOrFail();

        $first = $this->sell([[$phone, $unit->id]]);

        $this->assertSame('sold', $unit->fresh()->status);
        $this->assertSame(0, $phone->fresh()->availableQuantity());

        $this->cancel($first);

        $this->assertSame('in_stock', $unit->fresh()->status);
        $this->assertNull($unit->fresh()->sold_at);
        $this->assertSame(1, $phone->fresh()->availableQuantity());

        $second = $this->sell([[$phone, $unit->id]]);

        $this->assertSame('sold', $unit->fresh()->status);

        // "Sold to" follows the live agreement, not the cancelled one.
        $this->assertSame($second->id, $unit->fresh()->agreementItem->agreement_id);
    }

    public function test_the_counter_can_sell_stock_a_cancelled_agreement_gave_back(): void
    {
        $this->bootShop();
        $cable = $this->cable(3);
        $batch = $this->batch($cable, 3);

        $agreement = $this->sell([[$cable, null, $batch->id], [$cable, null, $batch->id]]);
        $this->cancel($agreement);

        // All three units are free again, and they come out of the same batch.
        (new PosCheckout($this->shop, $this->admin))->complete(
            [['product_id' => $cable->id, 'product_serial_id' => null, 'quantity' => 3]],
            [['method' => 'cash', 'amount' => '3000']],
        );

        $this->assertSame(1, Sale::count());
        $this->assertSame(0, $cable->fresh()->availableQuantity());
        $this->assertSame(0, $batch->fresh()->remainingQuantity());
        $this->assertSame([$batch->id], Sale::firstOrFail()->items->pluck('purchase_order_item_id')->unique()->all());
    }

    public function test_an_approved_agreement_cannot_be_cancelled_and_keeps_its_stock(): void
    {
        $this->bootShop();
        $phone = $this->phone('IMEI-1');
        $unit = $phone->serials()->firstOrFail();

        $agreement = $this->sell([[$phone, $unit->id]]);

        Volt::test('agreements.show', ['agreement' => $agreement])->call('approve');
        $this->assertSame('active', $agreement->fresh()->status);

        $this->cancel($agreement->fresh());

        $this->assertSame('active', $agreement->fresh()->status);
        $this->assertSame('sold', $unit->fresh()->status);
    }

    public function test_a_stale_page_cannot_cancel_an_agreement_approved_in_the_meantime(): void
    {
        $this->bootShop();
        $phone = $this->phone('IMEI-1');
        $unit = $phone->serials()->firstOrFail();

        $agreement = $this->sell([[$phone, $unit->id]]);

        // The page was opened while the agreement was still pending…
        $page = Volt::test('agreements.show', ['agreement' => $agreement]);

        // …and someone else approved it before the Cancel click arrived.
        $agreement->update(['status' => 'active']);

        $page->call('cancel');

        $this->assertSame('active', $agreement->fresh()->status);
        $this->assertSame('sold', $unit->fresh()->status);
    }

    public function test_staff_who_cannot_approve_agreements_cannot_cancel_them(): void
    {
        $this->bootShop();
        $phone = $this->phone('IMEI-1');
        $unit = $phone->serials()->firstOrFail();
        $agreement = $this->sell([[$phone, $unit->id]]);

        $this->actingAs($this->staff('Salesman'));

        Volt::test('agreements.show', ['agreement' => $agreement])->call('cancel')->assertForbidden();

        $this->assertSame('pending_approval', $agreement->fresh()->status);
        $this->assertSame('sold', $unit->fresh()->status);
    }

    // ── Agreements cancelled before this fix ───────────────────────────

    public function test_the_release_migration_frees_units_stranded_on_old_cancelled_agreements(): void
    {
        $this->bootShop();
        $phone = $this->phone('STRANDED', 'LIVE', 'RUNG-UP');
        [$stranded, $live, $rungUp] = $phone->serials()->orderBy('id')->get()->all();

        // Cancelled the old way: the unit was left marked sold.
        $this->agreementWith($phone, $stranded, 'cancelled');
        $stranded->update(['status' => 'sold', 'sold_at' => now()]);

        // On an agreement that is still live — must stay sold.
        $this->agreementWith($phone, $live, 'active');
        $live->update(['status' => 'sold', 'sold_at' => now()]);

        // Rung up over the counter at some point — must stay sold.
        (new PosCheckout($this->shop, $this->admin))->complete(
            [['product_id' => $phone->id, 'product_serial_id' => $rungUp->id, 'quantity' => 1]],
            [['method' => 'cash', 'amount' => '1000']],
        );
        $this->agreementWith($phone, $rungUp, 'cancelled');

        (require base_path('database/migrations/2026_10_13_000001_release_units_held_by_cancelled_agreements.php'))->up();

        $this->assertSame('in_stock', $stranded->fresh()->status);
        $this->assertNull($stranded->fresh()->sold_at);
        $this->assertSame('sold', $live->fresh()->status);
        $this->assertSame('sold', $rungUp->fresh()->status);
    }
}
