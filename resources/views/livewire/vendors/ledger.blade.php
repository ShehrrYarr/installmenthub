<?php

use App\Models\Vendor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public Vendor $vendor;

    public function mount(Vendor $vendor): void
    {
        $this->vendor = $vendor;
    }

    #[Computed]
    public function entries()
    {
        return $this->vendor->ledgerEntries()->orderBy('entry_date')->orderBy('id')->get();
    }

    #[Computed]
    public function summary(): array
    {
        return [
            'purchase_orders' => $this->vendor->purchaseOrders()->count(),
            'total_purchased' => (string) $this->vendor->purchaseOrders()->sum('total_amount'),
            'current_balance' => (string) ($this->entries->last()->running_balance ?? '0.00'),
        ];
    }
} ?>

@slot('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $vendor->name }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $vendor->contact_person }} · {{ $vendor->phone }}</p>
        </div>
        <a href="{{ route('tenant.vendors.edit', $vendor) }}" wire:navigate class="text-sm text-walnut-400 hover:text-walnut-400">Edit Vendor</a>
    </div>
@endslot

<div>
    <div class="space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
            <x-stat-card label="Purchase Orders" :value="$this->summary['purchase_orders']" color="walnut" />
            <x-stat-card label="Total Purchased" :value="'Rs. '.number_format((float) $this->summary['total_purchased'], 2)" color="sky" />
            <x-stat-card label="Balance Owed" :value="'Rs. '.number_format((float) $this->summary['current_balance'], 2)" color="rose" hint="Positive = shop owes vendor" />
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
            <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Ledger</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Date</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Description</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Debit</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Credit</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($this->entries as $entry)
                            <tr>
                                <td class="px-4 py-2.5 text-gray-500">{{ \Illuminate\Support\Carbon::parse($entry->entry_date)->format('d M Y') }}</td>
                                <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ $entry->description }}</td>
                                <td class="px-4 py-2.5 text-right text-rose-600">{{ $entry->type === 'debit' ? number_format((float) $entry->amount, 2) : '' }}</td>
                                <td class="px-4 py-2.5 text-right text-emerald-600">{{ $entry->type === 'credit' ? number_format((float) $entry->amount, 2) : '' }}</td>
                                <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ number_format((float) $entry->running_balance, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No ledger activity yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
