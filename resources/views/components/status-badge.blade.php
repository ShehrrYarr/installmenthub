@props(['status'])

@php
    $palette = match ($status) {
        'active', 'paid', 'completed', 'received', 'in_stock' => [
            'bg-emerald-100 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-900/40 dark:text-emerald-300',
        ],
        'pending', 'pending_approval', 'draft', 'partial', 'due_soon', 'trial', 'reserved', 'returned' => [
            'bg-amber-100 text-amber-700 ring-amber-600/20 dark:bg-amber-900/40 dark:text-amber-300',
        ],
        'overdue', 'defaulted', 'suspended', 'cancelled', 'defective' => [
            'bg-rose-100 text-rose-700 ring-rose-600/20 dark:bg-rose-900/40 dark:text-rose-300',
        ],
        default => [
            'bg-gray-100 text-gray-700 ring-gray-500/20 dark:bg-gray-700/40 dark:text-gray-300',
        ],
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {$palette[0]}"]) }}>
    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
    {{ ucwords(str_replace('_', ' ', $status)) }}
</span>
