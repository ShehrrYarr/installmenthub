@props([
    'model' => 'search',
    'debounce' => 300,
    'placeholder' => 'Search…',
])

<div {{ $attributes->merge(['class' => 'relative']) }}
     x-data="{
        slow: false,
        onSlow(e) { if (e.detail.props.includes('{{ $model }}')) this.slow = true },
        onEnd(e) { if (e.detail.props.includes('{{ $model }}')) this.slow = false },
     }"
     @search-loading-slow.window="onSlow($event)"
     @search-loading-end.window="onEnd($event)">
    <div wire:loading.remove wire:target="{{ $model }}" class="absolute left-3 top-2.5">
        <x-tenant-icon name="magnifying-glass" class="h-5 w-5 text-gray-400" />
    </div>
    <svg wire:loading wire:target="{{ $model }}" class="absolute left-3 top-2.5 h-5 w-5 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
    </svg>

    <input type="search"
           wire:model.live.debounce.{{ (int) $debounce }}ms="{{ $model }}"
           placeholder="{{ $placeholder }}"
           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 pl-10 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">

    <p x-show="slow" x-cloak
       class="absolute left-0 top-full mt-1 text-xs text-amber-600 dark:text-amber-400">
        Your internet seems slow, please wait…
    </p>
</div>
