<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Super Admin' }} — {{ config('app.name', 'InstallmentHub') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-walnut-50 dark:bg-walnut-900 text-walnut-900 dark:text-white">
        <div class="min-h-screen">
            @if (session('is_demo_session'))
                <div class="bg-amber-500 text-amber-950 text-center text-xs sm:text-sm font-medium px-4 py-1.5">
                    Demo Mode — you're using a shared seeded account. Changes may be reset at any time.
                </div>
            @endif

            <header class="sticky top-0 z-30 border-b border-walnut-200/60 dark:border-walnut-50/10 bg-walnut-50/80 dark:bg-walnut-900/80 backdrop-blur-xl">
                <div class="max-w-7xl mx-auto flex items-center justify-between px-4 sm:px-6 lg:px-8 py-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-walnut-600 text-white font-bold text-sm">IH</div>
                        <span class="font-semibold">InstallmentHub · Super Admin</span>
                    </div>

                    <nav class="hidden sm:flex items-center gap-6 text-sm font-medium text-walnut-900/70 dark:text-walnut-50/70">
                        <a href="{{ \Illuminate\Support\Facades\Route::has('superadmin.dashboard') ? route('superadmin.dashboard') : '#' }}" class="hover:text-walnut-900 dark:hover:text-white">Dashboard</a>
                        <a href="{{ \Illuminate\Support\Facades\Route::has('superadmin.shops.index') ? route('superadmin.shops.index') : '#' }}" class="hover:text-walnut-900 dark:hover:text-white">Shops</a>
                    </nav>

                    @auth
                        @livewire('layout.logout-button')
                    @endauth
                </div>
            </header>

            @isset($header)
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5">
                    {{ $header }}
                </div>
            @endisset

            <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
