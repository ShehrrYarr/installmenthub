<?php

use App\Models\Customer;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.customer')] class extends Component
{
    /**
     * Pinned from the URL at mount and carried in the (checksummed) snapshot.
     * Signing in happens on the shared /livewire/update endpoint, which never
     * runs the portal's tenant middleware — and the tenant resolves to null
     * there, since nobody is authenticated yet. Relying on ambient tenant
     * state would leave the lookup below unscoped across every shop.
     */
    public Shop $shop;

    #[Validate('required|string')]
    public string $login = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function mount(Shop $shop): void
    {
        $this->shop = $shop;
    }

    public function authenticate(): void
    {
        $this->validate();

        // Pin the tenant for the rest of this request so ShopScope confines
        // the lookup below — and everything downstream — to this shop.
        Tenant::set($this->shop);

        $this->ensureIsNotRateLimited();

        // shop_id is stated explicitly rather than left to ShopScope alone,
        // so this stays confined even if tenant context is ever lost. The
        // grouping matters: without it the orWhere would escape both filters.
        $customer = Customer::where('shop_id', $this->shop->id)
            ->where(fn ($query) => $query
            ->where('email', $this->login)
            ->orWhere('phone', $this->login))
            ->first();

        if (! $customer?->password || ! Hash::check($this->password, $customer->password)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => 'Those details don\'t match our records.',
            ]);
        }

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'login' => 'This account is inactive. Please contact the shop.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::guard('customer')->login($customer, $this->remember);
        session()->regenerate();

        $customer->forceFill(['portal_last_login_at' => now()])->saveQuietly();

        $this->redirect(route('customer.agreements', ['shop' => $this->shop]), navigate: false);
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => "Too many attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    private function throttleKey(): string
    {
        return 'customer-login|'.$this->shop->id.'|'.mb_strtolower($this->login).'|'.request()->ip();
    }
} ?>

<div class="mx-auto max-w-sm py-8">
    <div class="rounded-2xl border border-black/5 bg-white p-6 shadow-sm">
        <h1 class="text-lg font-semibold text-gray-900">Sign in</h1>
        <p class="mt-1 text-sm text-gray-500">View your agreements, instalments and statement.</p>

        <form wire:submit="authenticate" class="mt-6 space-y-4" wire:loading.class="opacity-50 pointer-events-none" wire:target="authenticate">
            <div>
                <x-input-label value="Phone number or email" />
                <x-text-input wire:model="login" autofocus autocomplete="username" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('login')" class="mt-1" />
            </div>

            <div>
                <x-input-label value="Password" />
                <x-text-input wire:model="password" type="password" autocomplete="current-password" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" wire:model="remember" class="rounded border-gray-300 text-[var(--theme-accent)] focus:ring-[var(--theme-accent)]">
                Keep me signed in
            </label>

            <button type="submit" wire:loading.attr="disabled" wire:target="authenticate"
                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[var(--theme-accent)] px-4 py-2.5 text-sm font-medium text-[var(--theme-accent-text)] disabled:opacity-50">
                <svg wire:loading wire:target="authenticate" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span wire:loading.remove wire:target="authenticate">Sign in</span>
                <span wire:loading wire:target="authenticate">Signing in…</span>
            </button>
        </form>
    </div>

    <p class="mt-4 text-center text-xs text-gray-400">
        Forgot your password? Please contact {{ $shop->name }} to have it reset.
    </p>
</div>
