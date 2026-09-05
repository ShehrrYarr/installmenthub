@props(['options', 'model', 'placeholder' => 'Select…', 'valueLabel' => ''])

<div x-data="localSelect({ options: {{ Illuminate\Support\Js::from($options) }}, model: '{{ $model }}', initialLabel: '{{ addslashes($valueLabel) }}' })"
     @click.outside="open = false"
     class="relative">
    <div class="relative">
        <input type="text"
               x-model="query"
               @focus="openList()"
               @input="open = true; highlighted = -1"
               @keydown.down.prevent="moveDown()"
               @keydown.up.prevent="moveUp()"
               @keydown.enter.prevent="chooseHighlighted()"
               @keydown.escape="open = false"
               autocomplete="off"
               placeholder="{{ $placeholder }}"
               {{ $attributes->merge(['class' => 'block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400 pr-8']) }}>

        <button type="button" x-show="query" x-cloak @click="clear()"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" tabindex="-1">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6L6 18M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div x-show="open && filtered.length" x-cloak
         class="absolute z-30 mt-1 w-full max-h-56 overflow-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 py-1 shadow-lg">
        <template x-for="(item, idx) in filtered" :key="item.id">
            <button type="button" @click="select(item)" @mouseenter="highlighted = idx"
                    class="block w-full px-3 py-2 text-left text-sm font-mono"
                    :class="highlighted === idx ? 'bg-walnut-50 dark:bg-walnut-900/40' : ''">
                <span x-text="item.label" class="text-gray-900 dark:text-white"></span>
            </button>
        </template>
    </div>

    <div x-show="open && !filtered.length" x-cloak
         class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-400 shadow-lg">
        No matches for "<span x-text="query"></span>"
    </div>
</div>
