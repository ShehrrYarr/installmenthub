<!DOCTYPE html>
@php
    $shopThemeKey = \App\Support\Tenant::current()?->theme ?? \App\Support\ShopThemes::DEFAULT;
    $shopTheme = \App\Support\ShopThemes::cssVariables($shopThemeKey);
    $shopThemeStyle = \App\Support\ShopThemes::style($shopThemeKey);
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-style="{{ $shopThemeStyle }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Dashboard' }} — {{ config('app.name', 'InstallmentHub') }}</title>

        @if ($shopThemeStyle === 'flat')
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles

        <style>
            :root {
                @foreach ($shopTheme as $var => $value)
                    {{ $var }}: {!! $value !!};
                @endforeach
            }

            {{-- The 'flat' theme (Indigo Clarity) is visually a different card
                 style, not just different colors, from the 3 glass themes it
                 shares this layout with — so instead of touching every card's
                 Tailwind classes across ~25 files, this scoped block reaches
                 in and overrides the specific glass-card utility classes only
                 when data-theme-style="flat" is on <html>. Never true for the
                 4 glass themes or for Super Admin's own /super-admin/* panel
                 (which doesn't render shop theme variables at all), so both
                 are byte-for-byte unaffected. --}}
            [data-theme-style="flat"] body {
                font-family: var(--theme-font);
            }
            [data-theme-style="flat"] .border-white\/40.bg-white\/70,
            [data-theme-style="flat"] .theme-card {
                background: var(--theme-card-bg) !important;
                border-color: var(--theme-card-border) !important;
                box-shadow: var(--theme-card-shadow) !important;
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
            }
            [data-theme-style="flat"] .bg-white\/80,
            [data-theme-style="flat"] .bg-white\/50,
            [data-theme-style="flat"] .bg-white\/90 {
                background: var(--theme-card-bg) !important;
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
            }
            [data-theme-style="flat"] .border-gray-200 {
                border-color: var(--theme-card-border) !important;
            }
            [data-theme-style="flat"] thead {
                background: var(--theme-table-header-bg) !important;
            }
            [data-theme-style="flat"] thead th {
                text-transform: uppercase !important;
                font-size: 0.6875rem !important;
                letter-spacing: 0.06em !important;
                font-weight: 600 !important;
                color: var(--theme-table-header-text) !important;
            }
        </style>
    </head>
    <body class="font-sans antialiased bg-[var(--theme-bg)] text-[var(--theme-bg-text)]">
        <div x-data="{ sidebarCollapsed: localStorage.getItem('sidebar-collapsed') === 'true' }"
             x-init="$watch('sidebarCollapsed', value => localStorage.setItem('sidebar-collapsed', value))"
             class="min-h-screen bg-[radial-gradient(ellipse_at_top,_var(--theme-accent-soft),_transparent_60%)] opacity-100">
            @auth
                @include('livewire.layout.tenant-nav')
            @endauth

            <div class="transition-[padding-left] duration-200" :class="sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64'">
                @if (session('is_demo_session'))
                    <div class="bg-amber-500 text-amber-950 text-center text-xs sm:text-sm font-medium px-4 py-1.5">
                        Demo Mode — you're using a shared seeded account. Changes may be reset at any time.
                    </div>
                @endif

                {{-- Mobile top bar --}}
                <header class="lg:hidden sticky top-0 z-30 flex items-center justify-between border-b border-gray-200 bg-white/80 backdrop-blur-xl px-4 py-3">
                    <div class="flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-[var(--theme-accent)] text-[var(--theme-accent-text)] font-bold text-xs">IH</div>
                        <span class="font-semibold text-gray-900 text-sm">{{ \App\Support\Tenant::current()?->name ?? config('app.name') }}</span>
                    </div>
                    @auth
                        <livewire:layout.tenant-user-menu :compact="true" />
                    @endauth
                </header>

                @isset($header)
                    <div class="border-b border-gray-200 bg-white/50 backdrop-blur px-4 sm:px-6 lg:px-8 py-5">
                        {{ $header }}
                    </div>
                @endisset

                <main class="px-4 sm:px-6 lg:px-8 py-6 pb-24 lg:pb-6">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
