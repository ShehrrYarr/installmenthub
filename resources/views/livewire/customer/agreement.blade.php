<?php

use App\Models\Agreement;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.customer')] class extends Component
{
    public Agreement $agreement;

    public function mount(Agreement $agreement): void
    {
        // The route is already scoped to this shop, but an agreement belonging
        // to a different customer of the same shop would still resolve — so
        // ownership is checked explicitly.
        abort_unless($agreement->customer_id === Auth::guard('customer')->id(), 403);

        $this->agreement = $agreement->load([
            'items.product',
            'items.productSerial',
            'schedules' => fn ($query) => $query->orderBy('installment_number'),
            'payments' => fn ($query) => $query->latest('paid_at'),
        ]);
    }
} ?>

@php($shop = \App\Support\Tenant::current())

@slot('header')
    <a href="{{ route('customer.agreements', ['shop' => $shop]) }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">← All agreements</a>
    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $agreement->items->first()?->product->name ?? 'Agreement' }}</h1>
            <p class="text-sm text-gray-500">{{ $agreement->agreement_number }}</p>
        </div>
        <x-status-badge :status="$agreement->status" />
    </div>
@endslot

<div class="space-y-5">

    <div class="rounded-2xl border border-black/5 bg-white p-5">
        <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-xs text-gray-500">Product price</dt>
                <dd class="mt-0.5 font-medium text-gray-900">Rs. {{ number_format((float) $agreement->product_price, 0) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Down payment</dt>
                <dd class="mt-0.5 font-medium text-gray-900">Rs. {{ number_format((float) $agreement->down_payment, 0) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Monthly instalment</dt>
                <dd class="mt-0.5 font-medium text-gray-900">Rs. {{ number_format((float) $agreement->monthly_installment, 0) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Duration</dt>
                <dd class="mt-0.5 font-medium text-gray-900">{{ $agreement->duration_months }} months</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">First instalment</dt>
                <dd class="mt-0.5 font-medium text-gray-900">{{ $agreement->first_due_date?->format('d M Y') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Total payable</dt>
                <dd class="mt-0.5 font-medium text-gray-900">Rs. {{ number_format((float) $agreement->total_payable, 0) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Remaining</dt>
                <dd class="mt-0.5 font-semibold text-gray-900">Rs. {{ number_format((float) $agreement->outstandingBalance(), 0) }}</dd>
            </div>
            @if ($serial = $agreement->items->first()?->productSerial)
                <div class="col-span-2">
                    <dt class="text-xs text-gray-500">Serial / IMEI</dt>
                    <dd class="mt-0.5 font-mono text-xs text-gray-700">{{ $serial->serial_number }}</dd>
                </div>
            @endif
        </dl>
    </div>

    <div class="overflow-hidden rounded-2xl border border-black/5 bg-white">
        <h2 class="p-4 pb-0 text-sm font-semibold text-gray-900">Instalment Schedule</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-medium text-gray-500">#</th>
                        <th class="px-4 py-2.5 text-left font-medium text-gray-500">Due date</th>
                        <th class="px-4 py-2.5 text-right font-medium text-gray-500">Due</th>
                        <th class="px-4 py-2.5 text-right font-medium text-gray-500">Paid</th>
                        <th class="px-4 py-2.5 text-left font-medium text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($agreement->schedules as $schedule)
                        <tr class="{{ $schedule->status === 'overdue' ? 'bg-rose-50/60' : '' }}">
                            <td class="px-4 py-2.5 text-gray-500">{{ $schedule->installment_number }}</td>
                            <td class="px-4 py-2.5 text-gray-700">{{ $schedule->due_date->format('d M Y') }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700">{{ number_format((float) $schedule->total_due, 0) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700">{{ number_format((float) $schedule->amount_paid, 0) }}</td>
                            <td class="px-4 py-2.5"><x-status-badge :status="$schedule->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-black/5 bg-white">
        <h2 class="p-4 pb-0 text-sm font-semibold text-gray-900">Payments</h2>
        <div class="mt-2 divide-y divide-gray-100">
            @forelse ($agreement->payments as $payment)
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">Rs. {{ number_format((float) $payment->amount, 0) }}</p>
                        <p class="text-xs text-gray-500">{{ $payment->paid_at->format('d M Y') }} · {{ $payment->receipt_number }}</p>
                    </div>
                    <a href="{{ route('customer.receipt', ['shop' => $shop, 'payment' => $payment]) }}" target="_blank"
                       class="shrink-0 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">
                        Receipt
                    </a>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-gray-400">No payments recorded yet.</p>
            @endforelse
        </div>
    </div>
</div>
