<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AgreementInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithAgreement(): array
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $shop = Shop::create([
            'name' => 'Test Shop', 'slug' => 'test-shop', 'is_active' => true,
            'address' => '123 Main St', 'city' => 'Karachi', 'phone' => '021-1234567', 'email' => 'shop@test.com',
        ]);

        $customer = Customer::create([
            'shop_id' => $shop->id, 'first_name' => 'Jane', 'last_name' => 'Doe',
            'cnic_number' => '12345-1234567-1', 'phone' => '03001234567', 'is_active' => true,
        ]);

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test AC', 'category' => 'ac', 'cash_price' => '50000', 'is_active' => true]);

        $agreement = Agreement::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id,
            'agreement_number' => 'AGR-TEST-0001', 'status' => 'active',
            'product_price' => '50000.00', 'down_payment' => '5000.00', 'processing_fee' => '500.00',
            'interest_rate' => '12.00', 'duration_months' => 12,
            'financed_amount' => '45500.00', 'total_interest' => '5460.00', 'total_payable' => '50960.00',
            'monthly_installment' => '4247.00', 'start_date' => now(), 'first_due_date' => now(),
        ]);

        AgreementItem::create(['shop_id' => $shop->id, 'agreement_id' => $agreement->id, 'product_id' => $product->id, 'unit_price' => '50000.00']);
        Guarantor::create(['shop_id' => $shop->id, 'agreement_id' => $agreement->id, 'name' => 'John Guarantor', 'relation' => 'Brother', 'mobile_number' => '03007654321', 'cnic_number' => '11111-1111111-1']);

        return [$shop, $agreement];
    }

    private function staffMember(Shop $shop, string $role): User
    {
        $user = User::factory()->create(['shop_id' => $shop->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_shop_admin_can_print_invoice_with_all_default_sections(): void
    {
        [$shop, $agreement] = $this->shopWithAgreement();
        $admin = $this->staffMember($shop, 'Shop Admin');

        $this->actingAs($admin)
            ->get("/s/{$shop->slug}/agreements/{$agreement->id}/invoice")
            ->assertOk()
            ->assertSee('AGR-TEST-0001')
            ->assertSee('Jane Doe')
            ->assertSee('Test AC')
            ->assertSee('John Guarantor');
    }

    public function test_manager_can_print_invoice_but_salesman_cannot(): void
    {
        [$shop, $agreement] = $this->shopWithAgreement();
        $manager = $this->staffMember($shop, 'Manager');
        $salesman = $this->staffMember($shop, 'Salesman');

        $this->actingAs($manager)
            ->get("/s/{$shop->slug}/agreements/{$agreement->id}/invoice")
            ->assertOk();

        $this->actingAs($salesman)
            ->get("/s/{$shop->slug}/agreements/{$agreement->id}/invoice")
            ->assertForbidden();
    }

    public function test_staff_cannot_print_another_shops_invoice(): void
    {
        [$shopA, $agreementA] = $this->shopWithAgreement();
        $shopB = Shop::create(['name' => 'Shop B', 'slug' => 'shop-b', 'is_active' => true]);
        $adminB = $this->staffMember($shopB, 'Shop Admin');

        $this->actingAs($adminB)
            ->get("/s/{$shopB->slug}/agreements/{$agreementA->id}/invoice")
            ->assertNotFound();
    }

    public function test_disabling_a_section_hides_it_from_the_invoice(): void
    {
        [$shop, $agreement] = $this->shopWithAgreement();
        $admin = $this->staffMember($shop, 'Shop Admin');

        $shop->update(['invoice_show_guarantors' => false]);

        $this->actingAs($admin)
            ->get("/s/{$shop->slug}/agreements/{$agreement->id}/invoice")
            ->assertOk()
            ->assertDontSee('John Guarantor');
    }

    public function test_terms_text_only_shows_when_toggle_is_on_and_text_is_set(): void
    {
        [$shop, $agreement] = $this->shopWithAgreement();
        $admin = $this->staffMember($shop, 'Shop Admin');

        $shop->update(['invoice_terms_text' => 'No refunds after 7 days.']);

        $this->actingAs($admin)
            ->get("/s/{$shop->slug}/agreements/{$agreement->id}/invoice")
            ->assertSee('No refunds after 7 days.');

        $shop->update(['invoice_show_terms' => false]);

        $this->actingAs($admin)
            ->get("/s/{$shop->slug}/agreements/{$agreement->id}/invoice")
            ->assertDontSee('No refunds after 7 days.');
    }

    public function test_only_shop_admin_can_access_the_invoice_settings_page(): void
    {
        [$shop] = $this->shopWithAgreement();
        $manager = $this->staffMember($shop, 'Manager');

        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);
        $this->actingAs($manager);

        Volt::test('settings.invoice')->assertForbidden();
    }

    public function test_shop_admin_can_toggle_a_section_off_and_it_persists(): void
    {
        [$shop] = $this->shopWithAgreement();
        $admin = $this->staffMember($shop, 'Shop Admin');

        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);
        $this->actingAs($admin);

        Volt::test('settings.invoice')
            ->assertSet('invoice_show_emi_schedule', true)
            ->set('invoice_show_emi_schedule', false)
            ->set('invoice_terms_text', 'Updated terms.')
            ->call('save')
            ->assertHasNoErrors();

        $shop->refresh();
        $this->assertFalse($shop->invoice_show_emi_schedule);
        $this->assertSame('Updated terms.', $shop->invoice_terms_text);
    }
}
