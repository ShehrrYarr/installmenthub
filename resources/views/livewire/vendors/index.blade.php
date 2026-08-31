<?php

use App\Models\Vendor;
use Livewire\Attributes\Url;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public string $status = 'all'; // all | active | inactive

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-vendors'), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function deactivate(int $vendorId): void
    {
        $vendor = Vendor::findOrFail($vendorId);
        $vendor->update(['is_active' => ! $vendor->is_active]);
    }

    public function with(): array
    {
        $vendors = Vendor::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('contact_person', 'like', "%{$this->search}%")))
            ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
            ->withCount('purchaseOrders')
            ->latest()
            ->paginate(12);

        return ['vendors' => $vendors];
    }
} ?>

@slot('header')
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Vendors</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Suppliers, bank details, and their document files</p>
            </div>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.vendors.create') ? route('tenant.vendors.create') : '#' }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                <x-tenant-icon name="plus" class="h-4 w-4" /> New Vendor
            </a>
        </div>
    @endslot

<div>

    <div class="space-y-4">
        <div class="flex flex-col sm:flex-row gap-3">
            <x-search-input model="search" placeholder="Search by name, phone, contact person…" class="flex-1" />
            <select wire:model.live="status" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" wire:loading.class="opacity-50 pointer-events-none" wire:target="search">
            @forelse ($vendors as $vendor)
                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-3">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $vendor->name }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $vendor->contact_person ?? '—' }}</p>
                        </div>
                        <x-status-badge :status="$vendor->is_active ? 'active' : 'suspended'" />
                    </div>

                    <dl class="text-sm space-y-1 text-gray-500 dark:text-gray-400">
                        <div class="flex items-center gap-2"><x-tenant-icon name="phone" class="h-4 w-4" /> {{ $vendor->phone }}</div>
                        <div>{{ $vendor->purchase_orders_count }} purchase order(s)</div>
                    </dl>

                    <div class="flex items-center gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
                        <a href="{{ route('tenant.vendors.ledger', $vendor) }}" wire:navigate
                           class="flex-1 text-center rounded-lg bg-gray-100 dark:bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200">
                            Ledger
                        </a>
                        <a href="{{ route('tenant.vendors.edit', $vendor) }}" wire:navigate
                           class="flex-1 text-center rounded-lg bg-gray-100 dark:bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200">
                            Edit
                        </a>
                        <button wire:click="deactivate({{ $vendor->id }})" wire:confirm="{{ $vendor->is_active ? 'Deactivate this vendor?' : 'Reactivate this vendor?' }}"
                            class="flex-1 rounded-lg bg-rose-50 dark:bg-rose-950/40 px-3 py-1.5 text-sm font-medium text-rose-600 dark:text-rose-300 hover:bg-rose-100">
                            {{ $vendor->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center text-gray-400">
                    No vendors found.
                </div>
            @endforelse
        </div>

        {{ $vendors->links() }}
    </div>
</div>
