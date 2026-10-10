@props(['active'])

@php
    $tabs = [
        ['key' => 'sales', 'label' => 'Sales', 'icon' => 'receipt', 'route' => 'tenant.sales.index', 'show' => true],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-bar', 'route' => 'tenant.sales.reports', 'show' => \App\Support\PosReports::canSeeProfit(auth()->user())],
        ['key' => 'closing', 'label' => 'Day Closing', 'icon' => 'banknotes', 'route' => 'tenant.sales.closing', 'show' => true],
    ];
@endphp

{{-- Same scrollable tab bar as Settings. --}}
<div class="relative mb-6">
    <div class="flex gap-1 overflow-x-auto no-scrollbar border-b border-black/10">
        @foreach (collect($tabs)->where('show', true) as $tab)
            @if ($tab['key'] === $active)
                <div class="flex shrink-0 items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2"
                     style="color: var(--theme-accent); border-color: var(--theme-accent)">
                    <x-tenant-icon :name="$tab['icon']" class="h-4 w-4" />
                    {{ $tab['label'] }}
                </div>
            @else
                <a href="{{ route($tab['route']) }}" wire:navigate
                   class="flex shrink-0 items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                    <x-tenant-icon :name="$tab['icon']" class="h-4 w-4" />
                    {{ $tab['label'] }}
                </a>
            @endif
        @endforeach
    </div>
    <div class="pointer-events-none absolute inset-y-0 left-0 w-6 bg-gradient-to-r from-[var(--theme-bg)] to-transparent"></div>
    <div class="pointer-events-none absolute inset-y-0 right-0 w-6 bg-gradient-to-l from-[var(--theme-bg)] to-transparent"></div>
</div>
