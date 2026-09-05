<?php

use App\Support\ShopThemes;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public string $selected;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['Shop Admin', 'Super Admin']), 403);

        $this->selected = Tenant::current()?->theme ?? ShopThemes::DEFAULT;
    }

    public function applyTheme(string $key): void
    {
        abort_unless(array_key_exists($key, ShopThemes::definitions()), 404);

        $shop = Tenant::current();
        abort_unless($shop, 403);

        $shop->update(['theme' => $key]);
        $this->selected = $key;

        session()->flash('status', 'Appearance updated — applied to the Admin, Manager, and Salesman panels for this shop.');

        // Full navigate so the layout's <style> block re-renders with the
        // new theme's CSS variables everywhere at once (sidebar included,
        // which lives outside this component's own render tree).
        $this->redirect(route('tenant.settings.appearance'), navigate: true);
    }
} ?>

@slot('header')
    <h1 class="text-xl font-semibold" style="color: var(--theme-bg-text)">Settings</h1>
    <p class="text-sm opacity-70" style="color: var(--theme-bg-text)">Manage how this shop looks and feels</p>
@endslot

<div>
    {{-- Settings sub-nav --}}
    <div class="flex gap-1 border-b border-black/10 mb-6">
        <div class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2"
             style="color: var(--theme-accent); border-color: var(--theme-accent)">
            <x-tenant-icon name="swatch" class="h-4 w-4" />
            Appearance
        </div>
        @if (auth()->user()->hasRole('Shop Admin'))
            <a href="{{ route('tenant.settings.staff') }}" wire:navigate
               class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <x-tenant-icon name="users" class="h-4 w-4" />
                Staff
            </a>
            <a href="{{ route('tenant.settings.emi') }}" wire:navigate
               class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <x-tenant-icon name="calculator" class="h-4 w-4" />
                EMI Settings
            </a>
        @endif
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-2xl border border-white/40 bg-white/70 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
        <h3 class="font-semibold text-gray-900 mb-1">Theme</h3>
        <p class="text-sm text-gray-500 mb-5">Pick a look for this shop. It applies immediately across the Admin, Manager, and Salesman panels.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach (\App\Support\ShopThemes::definitions() as $key => $theme)
                <button type="button" wire:click="applyTheme('{{ $key }}')"
                        class="text-left rounded-2xl border-2 p-4 transition {{ $selected === $key ? 'shadow-md' : 'border-gray-200 hover:border-gray-300' }}"
                        style="{{ $selected === $key ? 'border-color: '.$theme['sidebar'] : '' }}">
                    <div class="flex items-center justify-between mb-3">
                        <span class="font-medium text-gray-900">{{ $theme['label'] }}</span>
                        @if ($selected === $key)
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium text-white" style="background-color: {{ $theme['sidebar'] }}">
                                <x-tenant-icon name="check-circle" class="h-3.5 w-3.5" /> Active
                            </span>
                        @endif
                    </div>

                    {{-- Mini preview: background + sidebar strip + accent dots --}}
                    <div class="rounded-lg overflow-hidden border border-black/10 flex h-20">
                        <div class="w-8 h-full" style="background-color: {{ $theme['sidebar'] }}"></div>
                        <div class="flex-1 h-full p-2 flex items-end gap-1.5" style="background-color: {{ $theme['background'] }}">
                            <span class="h-3 w-3 rounded-full" style="background-color: {{ $theme['accent'] }}"></span>
                            <span class="h-3 w-3 rounded-full" style="background-color: {{ $theme['accentSoft'] }}"></span>
                            <span class="h-3 w-3 rounded-full" style="background-color: {{ $theme['accentAlt'] }}"></span>
                        </div>
                    </div>
                </button>
            @endforeach
        </div>
    </div>
</div>
