<?php

use App\Models\PurchaseOrder;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithPagination;

    public string $status = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-purchase-orders'), 403);
    }

    public function with(): array
    {
        $orders = PurchaseOrder::query()
            ->with('vendor')
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(12);

        return ['orders' => $orders];
    }
} ?>

@slot('header')
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Purchase Orders</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Stock inwarding and vendor invoices</p>
            </div>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.purchase-orders.create') ? route('tenant.purchase-orders.create') : '#' }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                <x-tenant-icon name="plus" class="h-4 w-4" /> New Purchase Order
            </a>
        </div>
    @endslot

<div>

    <div class="space-y-4">
        <select wire:model.live="status" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm">
            <option value="all">All statuses</option>
            <option value="draft">Draft</option>
            <option value="received">Received</option>
            <option value="cancelled">Cancelled</option>
        </select>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">PO #</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Vendor</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Date</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Total</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($orders as $order)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $order->po_number }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $order->vendor->name }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $order->order_date->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-right text-gray-900 dark:text-white">Rs. {{ number_format((float) $order->total_amount, 0) }}</td>
                                <td class="px-4 py-3"><x-status-badge :status="$order->status" /></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.purchase-orders.receive') ? route('tenant.purchase-orders.receive', $order) : '#' }}" wire:navigate
                                       class="text-walnut-400 hover:text-walnut-400 font-medium">
                                        {{ $order->status === 'draft' ? 'Receive' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No purchase orders yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $orders->links() }}
    </div>
</div>
