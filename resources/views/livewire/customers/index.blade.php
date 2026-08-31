<?php

use App\Models\Customer;
use Livewire\Attributes\Url;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $customers = Customer::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('first_name', 'like', "%{$this->search}%")
                ->orWhere('last_name', 'like', "%{$this->search}%")
                ->orWhere('cnic_number', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")))
            ->withCount('agreements')
            ->latest()
            ->paginate(12);

        return ['customers' => $customers];
    }
} ?>

@slot('header')
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Customers</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">KYC profiles and document files</p>
            </div>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.customers.create') ? route('tenant.customers.create') : '#' }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                <x-tenant-icon name="plus" class="h-4 w-4" /> New Customer
            </a>
        </div>
    @endslot

<div>

    <div class="space-y-4">
        <x-search-input model="search" placeholder="Search by name, CNIC, phone…" class="w-full sm:w-96" />

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" wire:loading.class="opacity-50 pointer-events-none" wire:target="search">
            @forelse ($customers as $customer)
                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-3">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $customer->first_name }} {{ $customer->last_name }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $customer->cnic_number }}</p>
                        </div>
                        <x-status-badge :status="$customer->is_active ? 'active' : 'suspended'" />
                    </div>

                    <dl class="text-sm space-y-1 text-gray-500 dark:text-gray-400">
                        <div class="flex items-center gap-2"><x-tenant-icon name="phone" class="h-4 w-4" /> {{ $customer->phone }}</div>
                        <div>{{ $customer->agreements_count }} agreement(s)</div>
                    </dl>

                    <div class="flex items-center gap-2">
                        <a href="{{ route('tenant.customers.ledger', $customer) }}" wire:navigate
                           class="flex-1 text-center rounded-lg bg-gray-100 dark:bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200">
                            Ledger
                        </a>
                        <a href="{{ route('tenant.customers.edit', $customer) }}" wire:navigate
                           class="flex-1 text-center rounded-lg bg-gray-100 dark:bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200">
                            Edit
                        </a>
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center text-gray-400">
                    No customers found.
                </div>
            @endforelse
        </div>

        {{ $customers->links() }}
    </div>
</div>
