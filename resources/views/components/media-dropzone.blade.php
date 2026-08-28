@props([
    'model',
    'existing' => [],
    'maxFiles' => 5,
    'maxSizeMb' => 4,
    'accept' => 'image/png,image/jpeg,image/webp',
    'removeMethod' => 'removeMedia',
    'label' => 'Documents',
])

<div
    x-data="mediaDropzone({ maxFiles: {{ (int) $maxFiles }}, maxSizeMb: {{ (int) $maxSizeMb }}, existingCount: {{ count($existing) }} })"
    class="space-y-3"
>
    <div class="flex items-center justify-between">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</p>
        <p class="text-xs text-gray-400" x-text="`${existingCount + previews.length}/${maxFiles} uploaded`"></p>
    </div>

    <div
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="onDrop($event)"
        @click="! isFull && $refs.input.click()"
        :class="{
            'border-walnut-400 bg-walnut-50 dark:bg-walnut-900/40': isDragging,
            'opacity-50 cursor-not-allowed': isFull,
            'cursor-pointer hover:border-walnut-200 dark:hover:border-walnut-400': ! isFull,
        }"
        class="flex flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-600 bg-white/60 dark:bg-gray-800/60 backdrop-blur px-4 py-6 text-center transition"
    >
        <svg class="h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 7.5m0 0L7.5 12m4.5-4.5v13.5" />
        </svg>
        <p class="text-sm text-gray-600 dark:text-gray-300" x-show="! isFull">
            <span class="font-semibold text-walnut-600 dark:text-walnut-400">Click to upload</span> or drag and drop
        </p>
        <p class="text-sm text-gray-500" x-show="isFull" x-cloak>Maximum of {{ $maxFiles }} files reached</p>
        <p class="text-xs text-gray-400">Up to {{ $maxFiles }} files, {{ $maxSizeMb }}MB each</p>

        <input
            x-ref="input"
            type="file"
            multiple
            accept="{{ $accept }}"
            wire:model="{{ $model }}"
            @change="onPick($event)"
            class="hidden"
        />
    </div>

    <p x-show="error" x-text="error" x-cloak class="text-sm text-rose-600"></p>
    <p x-show="isProcessing" x-cloak class="text-sm text-gray-400">Compressing images…</p>

    <div wire:loading wire:target="{{ $model }}" class="text-sm text-walnut-400">Uploading…</div>
    @error($model . '.*') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
    @error($model) <p class="text-sm text-rose-600">{{ $message }}</p> @enderror

    <div class="grid grid-cols-3 sm:grid-cols-5 gap-3" x-show="previews.length > 0 || {{ count($existing) }} > 0">
        @foreach ($existing as $media)
            <div class="relative group aspect-square overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                @if (str($media->mime_type)->startsWith('image'))
                    <img src="{{ $media->getUrl('thumb') }}" class="h-full w-full object-cover" alt="{{ $media->name }}">
                @else
                    <div class="flex h-full w-full items-center justify-center bg-gray-100 dark:bg-gray-700 text-[10px] text-gray-500 p-2 text-center break-all">{{ $media->file_name }}</div>
                @endif
                <button
                    type="button"
                    wire:click="{{ $removeMethod }}({{ $media->id }})"
                    wire:confirm="Remove this file?"
                    class="absolute top-1 right-1 hidden group-hover:flex h-6 w-6 items-center justify-center rounded-full bg-rose-600 text-white text-xs leading-none"
                >&times;</button>
            </div>
        @endforeach

        <template x-for="(preview, index) in previews" :key="index">
            <div class="relative aspect-square overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <img :src="preview.url" class="h-full w-full object-cover">
                <span class="absolute bottom-0 inset-x-0 bg-black/50 text-white text-[10px] px-1 py-0.5" x-text="formatSize(preview.size)"></span>
            </div>
        </template>
    </div>
</div>
