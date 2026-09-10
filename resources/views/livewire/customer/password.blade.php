<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.customer')] class extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function updatePassword(): void
    {
        $validated = $this->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $customer = Auth::guard('customer')->user();

        // Checked by hand rather than with the `current_password` rule, which
        // only ever looks at the default (staff) guard.
        if (! Hash::check($validated['current_password'], $customer->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $customer->update(['password' => $validated['password']]);

        $this->reset('current_password', 'password', 'password_confirmation');

        session()->flash('status', 'Password updated.');
    }
} ?>

@slot('header')
    <h1 class="text-xl font-semibold text-gray-900">Change Password</h1>
@endslot

<div class="mx-auto max-w-sm">
    @if (session('status'))
        <div class="mb-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-2xl border border-black/5 bg-white p-5">
        <form wire:submit="updatePassword" class="space-y-4" wire:loading.class="opacity-50 pointer-events-none" wire:target="updatePassword">
            <div>
                <x-input-label value="Current password" />
                <x-text-input wire:model="current_password" type="password" autocomplete="current-password" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('current_password')" class="mt-1" />
            </div>

            <div>
                <x-input-label value="New password" />
                <x-text-input wire:model="password" type="password" autocomplete="new-password" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>

            <div>
                <x-input-label value="Confirm new password" />
                <x-text-input wire:model="password_confirmation" type="password" autocomplete="new-password" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1" />
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="updatePassword"
                class="inline-flex w-full items-center justify-center rounded-lg bg-[var(--theme-accent)] px-4 py-2.5 text-sm font-medium text-[var(--theme-accent-text)] disabled:opacity-50">
                <span wire:loading.remove wire:target="updatePassword">Update password</span>
                <span wire:loading wire:target="updatePassword">Updating…</span>
            </button>
        </form>
    </div>
</div>
