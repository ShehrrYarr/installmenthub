<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.customer')] class extends Component
{
    #[Computed]
    public function agreements()
    {
        return Auth::guard('customer')->user()
            ->agreements()
            ->with(['items.product', 'schedules'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function totals(): array
    {
        $agreements = $this->agreements;
        $active = $agreements->whereIn('status', ['active', 'pending_approval']);

        return [
            'active' => $active->count(),
            'outstanding' => $active->reduce(
                fn ($carry, $agreement) => bcadd($carry, $agreement->outstandingBalance(), 2),
                '0.00'
            ),
            'overdue' => $agreements->flatMap->schedules->where('status', 'overdue')->reduce(
                fn ($carry, $schedule) => bcadd($carry, $schedule->balanceRemaining(), 2),
                '0.00'
            ),
        ];
    }
} ?>

@php($shop = \App\Support\Tenant::current())

@slot('header')
    <h1 class="text-xl font-semibold text-gray-900">My Agreements</h1>
    <p class="text-sm text-gray-500">Your instalment plans with {{ \App\Support\Tenant::current()?->name }}</p>
@endslot

<div class="space-y-5">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-black/5 bg-white p-4">
            <p class="text-xs text-gray-500">Active agreements</p>
            <p class="mt-1 text-xl font-semibold text-gray-900">{{ $this->totals['active'] }}</p>
        </div>
        <div class="rounded-2xl border border-black/5 bg-white p-4">
            <p class="text-xs text-gray-500">Outstanding</p>
            <p class="mt-1 text-xl font-semibold text-gray-900">Rs. {{ number_format((float) $this->totals['outstanding'], 0) }}</p>
        </div>
        <div class="col-span-2 rounded-2xl border p-4 sm:col-span-1 {{ bccomp($this->totals['overdue'], '0', 2) > 0 ? 'border-rose-200 bg-rose-50' : 'border-black/5 bg-white' }}">
            <p class="text-xs {{ bccomp($this->totals['overdue'], '0', 2) > 0 ? 'text-rose-600' : 'text-gray-500' }}">Overdue</p>
            <p class="mt-1 text-xl font-semibold {{ bccomp($this->totals['overdue'], '0', 2) > 0 ? 'text-rose-700' : 'text-gray-900' }}">
                Rs. {{ number_format((float) $this->totals['overdue'], 0) }}
            </p>
        </div>
    </div>

    <div>
        <div class="space-y-3">
            @forelse ($this->agreements as $agreement)
                <a href="{{ route('customer.agreement', ['shop' => $shop, 'agreement' => $agreement]) }}" wire:navigate
                   class="block rounded-2xl border border-black/5 bg-white p-4 transition hover:border-black/15">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900">{{ $agreement->items->first()?->product->name ?? 'Agreement' }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $agreement->agreement_number }} · started {{ $agreement->start_date?->format('d M Y') }}</p>
                        </div>
                        <x-status-badge :status="$agreement->status" />
                    </div>

                    <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <dt class="text-xs text-gray-500">Monthly</dt>
                            <dd class="font-medium text-gray-900">Rs. {{ number_format((float) $agreement->monthly_installment, 0) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">Total payable</dt>
                            <dd class="font-medium text-gray-900">Rs. {{ number_format((float) $agreement->total_payable, 0) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500">Remaining</dt>
                            <dd class="font-medium text-gray-900">Rs. {{ number_format((float) $agreement->outstandingBalance(), 0) }}</dd>
                        </div>
                    </dl>
                </a>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400">
                    You don't have any agreements yet.
                </div>
            @endforelse
        </div>
    </div>
</div>
