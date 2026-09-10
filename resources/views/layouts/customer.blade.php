<!DOCTYPE html>
@php
    $shop = \App\Support\Tenant::current();
    $shopThemeKey = $shop?->theme ?? \App\Support\ShopThemes::DEFAULT;
    $shopTheme = \App\Support\ShopThemes::cssVariables($shopThemeKey);
    $shopThemeStyle = \App\Support\ShopThemes::style($shopThemeKey);
    $customer = auth('customer')->user();
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-style="{{ $shopThemeStyle }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'My Account' }} — {{ $shop?->name ?? config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles

        <style>
            :root {
                @foreach ($shopTheme as $var => $value)
                    {{ $var }}: {!! $value !!};
                @endforeach
            }
        </style>
    </head>
    <body class="font-sans antialiased bg-[var(--theme-bg)] text-[var(--theme-bg-text)]">
        <div x-data="{ sidebarCollapsed: localStorage.getItem('customer-sidebar-collapsed') === 'true' }"
             x-init="$watch('sidebarCollapsed', value => localStorage.setItem('customer-sidebar-collapsed', value))"
             class="min-h-screen bg-[radial-gradient(ellipse_at_top,_var(--theme-accent-soft),_transparent_60%)]">

            @if ($customer)
                @include('layouts.partials.customer-nav')
            @endif

            <div class="transition-[padding-left] duration-200"
                 :class="{{ $customer ? "sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64'" : "''" }}">

                @if ($customer)
                    {{-- Mobile top bar --}}
                    <header class="lg:hidden sticky top-0 z-30 flex items-center justify-between border-b border-gray-200 bg-white/80 backdrop-blur-xl px-4 py-3">
                        <div class="flex min-w-0 items-center gap-2">
                            <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[var(--theme-accent)] text-[var(--theme-accent-text)] text-xs font-bold">
                                {{ \Illuminate\Support\Str::of($shop?->name ?? 'IH')->substr(0, 2)->upper() }}
                            </div>
                            <span class="truncate text-sm font-semibold text-gray-900">{{ $shop?->name ?? config('app.name') }}</span>
                        </div>
                        <span class="truncate text-xs text-gray-500">{{ $customer->full_name }}</span>
                    </header>
                @endif

                @isset($header)
                    <div class="border-b border-gray-200 bg-white/50 backdrop-blur px-4 sm:px-6 lg:px-8 py-5">
                        {{ $header }}
                    </div>
                @endisset

                <main class="px-4 sm:px-6 lg:px-8 py-6 {{ $customer ? 'pb-24 lg:pb-6' : 'pb-6' }}">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
