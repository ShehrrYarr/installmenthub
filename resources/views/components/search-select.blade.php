@props(['searchMethod', 'model', 'placeholder' => 'Type to search…', 'minChars' => 2, 'value' => null, 'valueLabel' => ''])

<div x-data="searchSelect({ searchMethod: '{{ $searchMethod }}', model: '{{ $model }}', minChars: {{ (int) $minChars }} })"
     x-init="query = '{{ addslashes($valueLabel) }}'"
     @click.outside="open = false"
     class="relative">
    <div class="relative">
        <input type="text"
               x-model="query"
               @input="runSearch()"
               @focus="results.length && (open = true)"
               @keydown.down.prevent="moveDown()"
               @keydown.up.prevent="moveUp()"
               @keydown.enter.prevent="chooseHighlighted()"
               @keydown.escape="open = false"
               autocomplete="off"
               placeholder="{{ $placeholder }}"
               {{ $attributes->merge(['class' => 'block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400 pr-8']) }}>

        <button type="button" x-show="query && !loading" x-cloak @click="clear()"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" tabindex="-1">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6L6 18M6 6l12 12" />
            </svg>
        </button>

        <svg x-show="loading" x-cloak class="absolute right-2 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
    </div>

    <div x-show="open && results.length" x-cloak
         class="absolute z-30 mt-1 w-full max-h-56 overflow-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 py-1 shadow-lg">
        <template x-for="(item, idx) in results" :key="item.id">
            <button type="button" @click="select(item)" @mouseenter="highlighted = idx"
                    class="block w-full px-3 py-2 text-left text-sm"
                    :class="highlighted === idx ? 'bg-walnut-50 dark:bg-walnut-900/40' : ''">
                <span x-text="item.label" class="block text-gray-900 dark:text-white"></span>
                <span x-show="item.sublabel" x-text="item.sublabel" class="block text-xs text-gray-400"></span>
            </button>
        </template>
    </div>

    <div x-show="open && !loading && !results.length && query.length >= {{ (int) $minChars }}" x-cloak
         class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-400 shadow-lg">
        No matches for "<span x-text="query"></span>"
    </div>
</div>
