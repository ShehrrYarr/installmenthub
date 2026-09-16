<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * "Try the Live Demo" (landing.blade.php's enterAsDemo) flags the session
 * with is_demo_session so a demo account's logout — however it happens —
 * lands back on the landing page instead of a shop login it has no real
 * reason to return to.
 */
class DemoLogoutTest extends TestCase
{
    use RefreshDatabase;

    private function shopAdmin(Shop $shop): User
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create(['shop_id' => $shop->id, 'is_active' => true]);
        $user->assignRole('Shop Admin');

        return $user;
    }

    public function test_demo_session_logout_redirects_to_landing_with_note(): void
    {
        $shop = Shop::create(['name' => 'Test Shop', 'slug' => 'test-shop', 'is_active' => true]);
        $user = $this->shopAdmin($shop);

        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);
        $this->actingAs($user)->withSession(['is_demo_session' => true]);

        Volt::test('layout.tenant-user-menu')
            ->call('logout')
            ->assertRedirect(route('landing', ['demo_ended' => 1]));

        $this->assertGuest();
    }

    public function test_regular_logout_still_goes_to_the_shops_own_login(): void
    {
        $shop = Shop::create(['name' => 'Test Shop', 'slug' => 'test-shop', 'is_active' => true]);
        $user = $this->shopAdmin($shop);

        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);
        $this->actingAs($user);

        Volt::test('layout.tenant-user-menu')
            ->call('logout')
            ->assertRedirect(route('login', ['shop' => $shop]));
    }

    public function test_deactivation_mid_demo_session_redirects_to_landing_not_the_deactivated_error(): void
    {
        $shop = Shop::create(['name' => 'Test Shop', 'slug' => 'test-shop', 'is_active' => true]);
        $user = $this->shopAdmin($shop);
        $user->update(['is_active' => false]);

        $response = $this->actingAs($user)
            ->withSession(['is_demo_session' => true])
            ->get('/dashboard');

        $response->assertRedirect(route('landing', ['demo_ended' => 1]));
        $response->assertSessionMissing('errors');
        $this->assertGuest();
    }

    public function test_deactivation_mid_regular_session_still_shows_the_deactivated_error(): void
    {
        $shop = Shop::create(['name' => 'Test Shop', 'slug' => 'test-shop', 'is_active' => true]);
        $user = $this->shopAdmin($shop);
        $user->update(['is_active' => false]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('login', ['shop' => $shop]));
        $response->assertSessionHasErrors('form.email');
    }
}
