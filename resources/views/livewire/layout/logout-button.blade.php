<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public function logout(): void
    {
        Auth::guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        $this->redirect(route('superadmin.login'), navigate: false);
    }
} ?>

<button wire:click="logout" type="button" class="text-sm font-medium text-walnut-900/70 dark:text-walnut-50/70 hover:text-walnut-900 dark:hover:text-white">
    Log out
</button>
