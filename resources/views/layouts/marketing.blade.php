<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'InstallmentHub') }}</title>
        <meta name="description" content="InstallmentHub — multi-tenant SaaS for electronics installment stores: EMI agreements, vendor & inventory management, collections, and role-based dashboards.">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-walnut-50 text-walnut-900">
        {{ $slot }}

        @livewireScripts
    </body>
</html>
