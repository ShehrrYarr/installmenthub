<?php

use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public Product $product;

    public function mount(Product $product): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $this->product = $product;
    }

    /** One row per physical unit — only meaningful for serialized products. */
    public function serials()
    {
        return $this->product->serials()
            ->with(['purchaseOrderItem.purchaseOrder.vendor', 'agreementItem.agreement.customer'])
            ->orderByDesc('id')
            ->get();
    }

    /** One row per purchase batch — the only "item" granularity that exists for a non-serialized product. */
    public function batches()
    {
        return $this->product->purchaseOrderItems()
            ->with(['purchaseOrder.vendor'])
            ->withSum('agreementItems as sold_quantity', 'quantity')
            ->orderByDesc('id')
            ->get();
    }
} ?>

@slot('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $product->name }} — Items</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Which vendor each unit came from, and what it cost</p>
        </div>
        <a href="{{ route('tenant.products.index') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
            Back to Products
        </a>
    </div>
@endslot

<div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
    <div class="overflow-x-auto">
        @if ($product->is_serialized)
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Serial / IMEI</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Vendor</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Cost Price</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Selling Price</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Sold To</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->serials() as $serial)
                        @php($batch = $serial->purchaseOrderItem)
                        <tr>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $serial->serial_number }}</td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ $batch?->purchaseOrder?->vendor?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $batch ? 'Rs. '.number_format((float) $batch->cost_price, 0) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $batch ? 'Rs. '.number_format((float) $batch->selling_cash_price, 0) : '—' }}</td>
                            <td class="px-4 py-2.5"><x-status-badge :status="$serial->status" /></td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">
                                @if ($serial->agreementItem?->agreement)
                                    <a href="{{ route('tenant.agreements.show', $serial->agreementItem->agreement) }}" wire:navigate class="text-walnut-400 hover:text-walnut-200">
                                        {{ $serial->agreementItem->agreement->agreement_number }}
                                    </a>
                                    <span class="text-xs text-gray-400">
                                        ({{ $serial->agreementItem->agreement->customer->first_name }} {{ $serial->agreementItem->agreement->customer->last_name }})
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No units received yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Purchase Order</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Vendor</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Cost Price</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Selling Price</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Received</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Sold</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Remaining</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->batches() as $batch)
                        @php($sold = (int) ($batch->sold_quantity ?? 0))
                        <tr>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                <a href="{{ route('tenant.purchase-orders.receive', $batch->purchaseOrder) }}" wire:navigate class="text-walnut-400 hover:text-walnut-200">
                                    {{ $batch->purchaseOrder->po_number }}
                                </a>
                            </td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ $batch->purchaseOrder->vendor->name }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">Rs. {{ number_format((float) $batch->cost_price, 0) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">Rs. {{ number_format((float) $batch->selling_cash_price, 0) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $batch->quantity }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $sold }}</td>
                            <td class="px-4 py-2.5 text-right font-medium {{ $batch->quantity - $sold > 0 ? 'text-emerald-600' : 'text-gray-400' }}">{{ $batch->quantity - $sold }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No purchase batches received yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    </div>
</div>
