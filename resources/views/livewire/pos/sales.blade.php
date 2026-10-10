<?php

use App\Models\Sale;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(Tenant::current()?->posEnabled(), 404);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $term = $this->search;

        $sales = Sale::query()
            ->with(['customer', 'seller', 'payments.bank'])
            ->withCount('items')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('sale_number', 'like', "%{$term}%")
                ->orWhere('walk_in_name', 'like', "%{$term}%")
                ->orWhere('walk_in_phone', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c
                    ->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"))))
            ->latest('sold_at')
            ->latest('id')
            ->paginate(15);

        return ['sales' => $sales];
    }
} ?>

@slot('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Sales</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Counter sales rung up on the POS</p>
        </div>
        <a href="{{ route('tenant.pos.register') }}" wire:navigate
           class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
            <x-tenant-icon name="plus" class="h-4 w-4" /> New Sale
        </a>
    </div>
@endslot

<div class="space-y-4">
    <x-search-input model="search" placeholder="Search by sale number, customer name or phone…" class="w-full sm:w-96" />

    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden"
         wire:loading.class="opacity-50 pointer-events-none" wire:target="search">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Sale #</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Date</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Customer</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Items</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Paid By</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Total</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Sold By</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($sales as $sale)
                        <tr wire:key="sale-{{ $sale->id }}">
                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $sale->sale_number }}</td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $sale->sold_at->format('d M Y, h:i A') }}</td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                {{ $sale->customerName() }}
                                @if ($sale->isWalkIn())
                                    <span class="ml-1 text-xs text-gray-400">walk-in</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right text-gray-500 dark:text-gray-400">{{ $sale->items_count }}</td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $sale->paymentSummary() }}</td>
                            <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ \App\Support\PosCalculator::money((string) $sale->total) }}</td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $sale->seller?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('tenant.sales.show', $sale) }}" wire:navigate class="text-walnut-400 hover:text-walnut-200 font-medium text-xs">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No sales yet — ring one up on the POS.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $sales->links() }}
</div>
