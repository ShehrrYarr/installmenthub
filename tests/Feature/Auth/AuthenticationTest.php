<?php

namespace Tests\Feature\Auth;

use App\Models\Shop;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'Test Shop', 'slug' => 'test-shop']);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $shop = $this->shop();

        $response = $this->get("/{$shop->slug}/login");

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.login');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $shop = $this->shop();
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $component = Volt::test('pages.auth.login', ['shop' => $shop])
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('tenant.dashboard', $shop, absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $shop = $this->shop();
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $component = Volt::test('pages.auth.login', ['shop' => $shop])
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_users_cannot_authenticate_at_another_shops_login(): void
    {
        $ownShop = $this->shop();
        $otherShop = Shop::create(['name' => 'Other Shop', 'slug' => 'other-shop']);
        $user = User::factory()->create(['shop_id' => $ownShop->id]);

        $component = Volt::test('pages.auth.login', ['shop' => $otherShop])
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_superadmin_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/superadmin/login');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.superadmin-login');
    }

    public function test_superadmin_can_authenticate_via_superadmin_login(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $component = Volt::test('pages.auth.superadmin-login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('superadmin.dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_non_superadmin_cannot_authenticate_via_superadmin_login(): void
    {
        $shop = $this->shop();
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $component = Volt::test('pages.auth.superadmin-login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_navigation_menu_can_be_rendered(): void
    {
        $shop = $this->shop();
        $user = User::factory()->create(['shop_id' => $shop->id, 'is_active' => true]);

        $this->actingAs($user);

        // layout.navigation only appears on the generic x-app-layout pages
        // (e.g. /profile) — tenant dashboards use layouts.tenant's own
        // inlined sidebar instead.
        $response = $this->get('/profile');

        $response
            ->assertOk()
            ->assertSeeVolt('layout.navigation');
    }

    public function test_users_can_logout(): void
    {
        $shop = $this->shop();
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        $component = Volt::test('layout.navigation');

        $component->call('logout');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
