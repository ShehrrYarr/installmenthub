<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\PosDemoSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\ShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The Point of Sale in the live demo: the demo shop gets it switched on, with
 * counter stock and a week of sample sales, and the demo's first screens say so.
 */
class PosDemoTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemoShops(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(ShopSeeder::class);
    }

    private function demoShop(): Shop
    {
        return Shop::where('slug', 'karachi-electronics-hub')->firstOrFail();
    }

    private function sales(Shop $shop)
    {
        return Sale::withoutGlobalScopes()->where('shop_id', $shop->id);
    }

    public function test_pos_is_switched_on_for_the_demo_shop_only(): void
    {
        $this->seedDemoShops();

        $this->assertFalse($this->demoShop()->pos_enabled);

        $this->seed(PosDemoSeeder::class);

        $this->assertTrue($this->demoShop()->fresh()->pos_enabled);
        $this->assertFalse(Shop::where('slug', 'lahore-installment-store')->firstOrFail()->pos_enabled);
    }

    public function test_the_demo_shop_gets_counter_stock_and_a_week_of_sample_sales(): void
    {
        $this->seedDemoShops();
        $this->seed(PosDemoSeeder::class);

        $shop = $this->demoShop();

        $this->assertSame(6, $this->sales($shop)->count());
        $this->assertSame(1, SaleReturn::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertTrue(Bank::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'EasyPaisa')->exists());

        // One credit sale to a registered customer, part-paid and then repaid in part.
        $credit = $this->sales($shop)->whereNotNull('customer_id')->get();
        $this->assertCount(1, $credit);
        $this->assertSame('155000.00', (string) $credit->first()->total);
        $this->assertSame('70000.00', (string) $credit->first()->amount_paid);
        $this->assertNotNull($credit->first()->due_date);

        // Spread over the week, not all stamped with the seeding moment.
        $this->assertTrue(Carbon::parse($this->sales($shop)->min('sold_at'))->lt(now()->subDays(5)));

        // Stock reflects what was sold (and the one return).
        Tenant::set($shop);
        $stock = fn (string $sku) => Product::where('sku', $sku)->firstOrFail()->availableQuantity();

        $this->assertSame(36, $stock('CHARGER-20W'));
        $this->assertSame(21, $stock('EARBUDS-BT'));
        $this->assertSame(55, $stock('SCREEN-GUARD'));
        $this->assertSame(4, Product::where('name', 'like', '%Haier%')->firstOrFail()->availableQuantity());
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->seedDemoShops();
        $this->seed(PosDemoSeeder::class);

        $shop = $this->demoShop();
        $products = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count();

        $this->seed(PosDemoSeeder::class);

        $this->assertSame(6, $this->sales($shop)->count());
        $this->assertSame(1, SaleReturn::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame($products, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame(1, Bank::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_it_leaves_no_tenant_or_frozen_clock_behind(): void
    {
        $this->seedDemoShops();
        $this->seed(PosDemoSeeder::class);

        $this->assertNull(Tenant::current());
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function test_it_does_nothing_where_there_is_no_demo_shop(): void
    {
        $this->seed(PosDemoSeeder::class);

        $this->assertSame(0, Shop::count());
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }

    public function test_the_salesman_lands_on_a_dashboard_with_a_counter_sale_shortcut(): void
    {
        $this->seedDemoShops();
        $this->seed(PosDemoSeeder::class);

        $shop = $this->demoShop();
        $salesman = User::where('email', 'salesman1@installmenthub.test')->firstOrFail();

        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);
        $this->actingAs($salesman);

        // Only today's sale (Rs. 2,300) counts towards "sold today".
        Volt::test('dashboard.salesman')
            ->assertSee('New Counter Sale')
            ->assertSee('My Sales Today')
            ->assertSee('Rs. 2,300');

        // A shop without the add-on sees none of it.
        $shop->update(['pos_enabled' => false]);
        Tenant::set($shop->fresh());

        Volt::test('dashboard.salesman')
            ->assertDontSee('New Counter Sale')
            ->assertDontSee('My Sales Today');
    }

    public function test_the_landing_page_tells_visitors_about_counter_sales(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('Counter Sales (POS)')
            ->assertSee('Mobile-First Interface');
    }
}
