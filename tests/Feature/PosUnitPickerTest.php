<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenant;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Tapping a serialized product opens a pop-up to choose the exact unit —
 * always, even with one unit left — with a search box and per-unit detail.
 */
class PosUnitPickerTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private function bootShop(string $role = 'Shop Admin'): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->shop = Shop::create([
            'name' => 'Picker Shop', 'slug' => 'picker-shop', 'is_active' => true, 'pos_enabled' => true,
            'address' => '1 Market Rd', 'phone' => '021-111',
        ]);

        Tenant::set($this->shop);
        URL::defaults(['shop' => $this->shop->slug]);
        $this->actingAs($this->staff($role));
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['shop_id' => $this->shop->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function phone(): Product
    {
        return Product::create([
            'shop_id' => $this->shop->id, 'name' => 'Phone X', 'category' => 'mobile', 'sku' => 'PHN-X',
            'cash_price' => '50000', 'cost_price' => '42000', 'is_serialized' => true,
            'stock_quantity' => 0, 'reorder_level' => 1, 'is_active' => true,
        ]);
    }

    private function unit(Product $product, string $imei, ?PurchaseOrderItem $batch = null): ProductSerial
    {
        return ProductSerial::create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'serial_number' => $imei,
            'status' => 'in_stock', 'purchase_order_item_id' => $batch?->id,
        ]);
    }

    private function batch(Product $product, string $vendorName, string $cost): PurchaseOrderItem
    {
        $vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => $vendorName, 'phone' => '0300', 'is_active' => true]);

        $order = PurchaseOrder::create([
            'shop_id' => $this->shop->id, 'vendor_id' => $vendor->id, 'po_number' => 'PO-'.random_int(1000, 9999),
            'order_date' => now(), 'status' => 'received', 'payment_mode' => 'cash',
        ]);

        return PurchaseOrderItem::create([
            'shop_id' => $this->shop->id, 'purchase_order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => 5, 'cost_price' => $cost, 'selling_cash_price' => '50000', 'subtotal' => bcmul($cost, '5', 2),
        ]);
    }

    public function test_tapping_a_serialized_product_opens_a_popup_listing_its_units(): void
    {
        $this->bootShop();
        $phone = $this->phone();
        $this->unit($phone, 'IMEI-0001');
        $this->unit($phone, 'IMEI-0002');
        $this->unit($phone, 'IMEI-0003');

        Volt::test('pos.register')
            ->call('addProduct', $phone->id)
            ->assertSet('pickingProductId', $phone->id)
            ->assertCount('cart', 0)
            ->assertSee('Which unit of Phone X?')
            ->assertSee('3 units')
            ->assertSee('IMEI-0001')
            ->assertSee('IMEI-0002')
            ->assertSee('IMEI-0003')
            ->assertSee('Search IMEI / serial number');
    }

    public function test_it_is_asked_even_when_only_one_unit_is_left(): void
    {
        $this->bootShop();
        $phone = $this->phone();
        $only = $this->unit($phone, 'ONLY-ONE');

        Volt::test('pos.register')
            ->call('addProduct', $phone->id)
            ->assertSet('pickingProductId', $phone->id)
            ->assertCount('cart', 0)
            ->assertSee('ONLY-ONE')
            ->call('addSerial', $only->id)
            ->assertSet('pickingProductId', null)
            ->assertCount('cart', 1);
    }

    public function test_each_unit_shows_its_vendor_date_received_and_cost_to_the_owner(): void
    {
        $this->bootShop('Shop Admin');
        $phone = $this->phone();
        $this->unit($phone, 'IMEI-0001', $this->batch($phone, 'Acme Distributors', '41000'));
        $this->unit($phone, 'IMEI-0002');

        Volt::test('pos.register')
            ->call('addProduct', $phone->id)
            ->assertSee('Acme Distributors')
            ->assertSee('No vendor on record')
            ->assertSee('Received '.now()->format('d M Y'))
            // The batch's own cost, and the product's cost for a unit with no batch.
            ->assertSee('Cost Rs. 41,000')
            ->assertSee('Cost Rs. 42,000');
    }

    public function test_cost_is_hidden_from_a_salesman(): void
    {
        $this->bootShop('Salesman');
        $phone = $this->phone();
        $this->unit($phone, 'IMEI-0001', $this->batch($phone, 'Acme Distributors', '41000'));

        Volt::test('pos.register')
            ->call('addProduct', $phone->id)
            ->assertSee('Acme Distributors')
            ->assertSee('Received')
            ->assertDontSee('Cost Rs.');
    }

    public function test_the_search_box_narrows_the_units_and_is_cleared_on_close(): void
    {
        $this->bootShop();
        $phone = $this->phone();
        $this->unit($phone, 'IMEI-0001');
        $this->unit($phone, 'IMEI-0002');

        Volt::test('pos.register')
            ->call('addProduct', $phone->id)
            ->set('unitSearch', '0002')
            ->assertSee('IMEI-0002')
            ->assertDontSee('IMEI-0001')
            ->assertSee('1 unit')
            ->set('unitSearch', 'no-such-imei')
            ->assertSee('No unit matches')
            ->set('unitSearch', '0002')
            ->call('cancelPicking')
            ->assertSet('pickingProductId', null)
            ->assertSet('unitSearch', '')
            // And a fresh pop-up starts with the full list again.
            ->call('addProduct', $phone->id)
            ->assertSee('IMEI-0001')
            ->assertSee('IMEI-0002');
    }

    public function test_choosing_a_unit_closes_the_popup_and_it_is_not_offered_again(): void
    {
        $this->bootShop();
        $phone = $this->phone();
        $first = $this->unit($phone, 'IMEI-0001');
        $this->unit($phone, 'IMEI-0002');

        $register = Volt::test('pos.register')
            ->call('addProduct', $phone->id)
            ->set('unitSearch', '0001')
            ->call('addSerial', $first->id)
            ->assertSet('pickingProductId', null)
            ->assertSet('unitSearch', '')
            ->assertCount('cart', 1)
            ->call('addProduct', $phone->id)
            ->assertSet('pickingProductId', $phone->id);

        // The unit already on the sale is not on the list a second time.
        $this->assertSame(['IMEI-0002'], $register->instance()->pickerSerials->pluck('serial_number')->all());
    }

    public function test_a_scanned_imei_goes_straight_in_but_a_scanned_sku_opens_the_popup(): void
    {
        $this->bootShop();
        $phone = $this->phone();
        $this->unit($phone, 'IMEI-0001');
        $this->unit($phone, 'IMEI-0002');

        Volt::test('pos.register')
            ->set('scan', 'IMEI-0002')->call('submitScan')
            ->assertCount('cart', 1)
            ->assertSet('pickingProductId', null)
            ->set('scan', 'PHN-X')->call('submitScan')
            ->assertSet('pickingProductId', $phone->id);
    }

    public function test_nothing_opens_when_every_unit_is_already_on_the_sale(): void
    {
        $this->bootShop();
        $phone = $this->phone();
        $only = $this->unit($phone, 'ONLY-ONE');

        Volt::test('pos.register')
            ->call('addSerial', $only->id)
            ->call('addProduct', $phone->id)
            ->assertSet('pickingProductId', null)
            ->assertSet('noticeIsError', true)
            ->assertCount('cart', 1);
    }

    public function test_a_long_list_is_capped_with_a_note_to_search(): void
    {
        $this->bootShop();
        $phone = $this->phone();

        foreach (range(1, 105) as $n) {
            $this->unit($phone, sprintf('BULK-%04d', $n));
        }

        $register = Volt::test('pos.register')
            ->call('addProduct', $phone->id)
            ->assertSee('105 units')
            ->assertSee('Showing the first 100')
            ->assertSee('BULK-0001')
            ->assertDontSee('BULK-0105');

        $this->assertCount(100, $register->instance()->pickerSerials);

        // Searching reaches the rest.
        $register->set('unitSearch', '0105')->assertSee('BULK-0105')->assertDontSee('Showing the first');
    }
}
