<?php

use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public Customer $customer;

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
    }

    #[Computed]
    public function agreements()
    {
        return $this->customer->agreements()->withCount('schedules')->latest()->get();
    }

    #[Computed]
    public function entries()
    {
        return $this->customer->ledgerEntries()->orderBy('entry_date')->orderBy('id')->get();
    }

    #[Computed]
    public function summary(): array
    {
        $agreements = $this->agreements;

        return [
            'total' => $agreements->count(),
            'active' => $agreements->where('status', 'active')->count(),
            'completed' => $agreements->where('status', 'completed')->count(),
            'down_payments' => (string) $agreements->sum('down_payment'),
            'interest_charged' => (string) $agreements->sum('total_interest'),
            'collected' => (string) $this->customer->payments()->sum('amount'),
            'penalties' => (string) \App\Models\InstallmentSchedule::whereIn('agreement_id', $agreements->pluck('id'))->sum('penalty_amount'),
            'outstanding' => (string) $agreements->whereIn('status', ['active', 'pending_approval'])->reduce(
                fn ($carry, $agreement) => bcadd($carry, $agreement->outstandingBalance(), 2),
                '0.00'
            ),
        ];
    }
} ?>

@slot('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $customer->first_name }} {{ $customer->last_name }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $customer->cnic_number }} · {{ $customer->phone }}</p>
        </div>
        <a href="{{ route('tenant.customers.edit', $customer) }}" wire:navigate class="text-sm text-walnut-400 hover:text-walnut-400">Edit Profile</a>
    </div>
@endslot

<div>
    <div class="space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-stat-card label="Agreements" :value="$this->summary['total']" hint="{{ $this->summary['active'] }} active · {{ $this->summary['completed'] }} completed" color="walnut" />
            <x-stat-card label="Down Payments" :value="'Rs. '.number_format((float) $this->summary['down_payments'], 2)" color="sky" />
            <x-stat-card label="Interest Charged" :value="'Rs. '.number_format((float) $this->summary['interest_charged'], 2)" color="amber" />
            <x-stat-card label="Collected" :value="'Rs. '.number_format((float) $this->summary['collected'], 2)" color="emerald" />
            <x-stat-card label="Penalties Levied" :value="'Rs. '.number_format((float) $this->summary['penalties'], 2)" color="rose" />
            <x-stat-card label="Net Outstanding" :value="'Rs. '.number_format((float) $this->summary['outstanding'], 2)" color="walnut" />
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Agreements</h3>
            <div class="space-y-2">
                @forelse ($this->agreements as $agreement)
                    <a href="{{ route('tenant.agreements.show', $agreement) }}" wire:navigate class="flex items-center justify-between rounded-xl bg-gray-50 dark:bg-gray-900/40 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-900/70">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $agreement->agreement_number }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Rs. {{ number_format((float) $agreement->monthly_installment, 2) }}/mo · {{ $agreement->duration_months }} mo</p>
                        </div>
                        <x-status-badge :status="$agreement->status" />
                    </a>
                @empty
                    <p class="text-sm text-gray-400">No agreements yet.</p>
                @endforelse
            </div>
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
