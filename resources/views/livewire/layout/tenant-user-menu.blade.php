<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $compact = false;

    // The icon-only trigger is reused for the collapsed desktop sidebar
    // footer as well as the mobile top bar — the mobile bar sits near the
    // top of the screen (dropdown opens down), but the sidebar footer sits
    // at the very bottom (opening down there would run off-screen), so that
    // usage passes this to flip the dropdown to open upward instead.
    public bool $openUpward = false;

    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect(route('landing'), navigate: false);
    }
} ?>

@if ($compact)
    {{-- Mobile top bar: icon-only trigger on a light header, dropdown opens downward --}}
    <div x-data="{ open: false }" @click.outside="open = false" class="relative">
        <button type="button" @click="open = ! open"
                class="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--theme-accent)] text-[var(--theme-accent-text)] text-xs font-semibold">
            {{ Str::of(auth()->user()->name)->substr(0, 1)->upper() }}
        </button>

        <div x-show="open" x-cloak x-transition
             class="absolute z-50 w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg {{ $openUpward ? 'left-0 bottom-full mb-2' : 'right-0 top-full mt-2' }}">
            <div class="px-4 py-2 border-b border-gray-100">
                <p class="truncate text-sm font-medium text-gray-900">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-gray-500">{{ auth()->user()->getRoleNames()->first() ?? 'User' }}</p>
            </div>
            <a href="{{ route('tenant.profile') }}" wire:navigate class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                Profile
            </a>
            <button wire:click="logout" type="button" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">
                Log Out
            </button>
        </div>
    </div>
@else
    {{-- Desktop sidebar footer: full row on the shop's themed sidebar background, dropdown opens upward --}}
    <div x-data="{ open: false }" @click.outside="open = false" class="relative">
        <button type="button" @click="open = ! open"
                class="flex w-full items-center gap-2 rounded-xl px-2 py-2 text-left transition hover:bg-[var(--theme-sidebar-hover-bg)]">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--theme-accent)] text-[var(--theme-accent-text)] text-xs font-semibold">
                {{ Str::of(auth()->user()->name)->substr(0, 1)->upper() }}
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-[var(--theme-sidebar-text)]">{{ auth()->user()->name }}</span>
                <span class="block truncate text-xs text-[var(--theme-sidebar-text-muted)]">{{ auth()->user()->getRoleNames()->first() ?? 'User' }}</span>
            </span>
            <svg class="h-4 w-4 shrink-0 text-[var(--theme-sidebar-text-muted)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
            </svg>
        </button>

        <div x-show="open" x-cloak x-transition
             class="absolute bottom-full left-0 z-50 mb-2 w-full min-w-[10rem] overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg">
            <a href="{{ route('tenant.profile') }}" wire:navigate class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                Profile
            </a>
            <button wire:click="logout" type="button" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">
                Log Out
            </button>
        </div>
    </div>
@endif
