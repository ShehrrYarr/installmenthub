<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    //
} ?>

@slot('header')
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Profile</h1>
@endslot

<div class="space-y-6 max-w-xl">
    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
        <livewire:profile.update-profile-information-form />
    </div>

    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
        <livewire:profile.update-password-form />
    </div>
</div>
