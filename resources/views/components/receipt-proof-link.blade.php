@props([
    'proof' => null,
    'missing' => false,
    'routeName' => 'tenant.receipt-proof',
])

@if ($proof)
    <a href="{{ route($routeName, ['media' => $proof]) }}" target="_blank" rel="noopener"
       title="Receipt / proof of transfer — {{ $proof->file_name }}"
       {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center align-middle text-gray-400 transition hover:text-walnut-600 dark:hover:text-walnut-400']) }}>
        <x-tenant-icon name="paper-clip" class="h-4 w-4" />
        <span class="sr-only">View receipt proof</span>
    </a>
@elseif ($missing)
    <span title="Paid by bank, but no receipt attached"
          {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center align-middle text-amber-500']) }}>
        <x-tenant-icon name="exclamation-triangle" class="h-4 w-4" />
        <span class="sr-only">Paid by bank with no receipt attached</span>
    </span>
@endif
