@props(['label', 'value', 'icon' => null, 'color' => 'walnut', 'hint' => null])

@php
    // Full literal classes per color (Tailwind's JIT scanner needs these as
    // complete strings — interpolating "bg-{{ $color }}-100" would not be picked up).
    $palette = match ($color) {
        'emerald' => ['icon' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300', 'glow' => 'bg-emerald-400/10'],
        'amber' => ['icon' => 'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300', 'glow' => 'bg-amber-400/10'],
        'rose' => ['icon' => 'bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-300', 'glow' => 'bg-rose-400/10'],
        'sky' => ['icon' => 'bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-300', 'glow' => 'bg-sky-400/10'],
        default => ['icon' => 'bg-walnut-200 text-walnut-600 dark:bg-walnut-900/40 dark:text-walnut-200', 'glow' => 'bg-walnut-400/10'],
    };
@endphp

<div class="relative overflow-hidden rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
    <div class="relative flex items-start justify-between">
        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $value }}</p>
            @if ($hint)
                <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
            @endif
        </div>
        @if ($icon)
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $palette['icon'] }}">
                {{ $icon }}
            </div>
        @endif
    </div>
    <div class="pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full {{ $palette['glow'] }} blur-2xl"></div>
</div>
