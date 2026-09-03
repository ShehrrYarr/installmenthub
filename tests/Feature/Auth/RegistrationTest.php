<?php

namespace Tests\Feature\Auth;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'Test Shop', 'slug' => 'test-shop']);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $shop = $this->shop();

        $response = $this->get("/{$shop->slug}/register");

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    public function test_new_users_can_register(): void
    {
        $shop = $this->shop();

        $component = Volt::test('pages.auth.register', ['shop' => $shop])
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }
}
