<?php

use App\Models\Agreement;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public Agreement $agreement;

    public function mount(Agreement $agreement): void
    {
        $this->agreement = $agreement->load([
            'customer', 'salesman', 'approver', 'guarantors',
            'items.product', 'items.productSerial',
            'schedules' => fn ($q) => $q->orderBy('installment_number'),
            'payments' => fn ($q) => $q->latest('paid_at'),
        ]);
    }

    public function approve(): void
    {
        abort_unless(auth()->user()->can('approve-agreements'), 403);

        if ($this->agreement->status !== 'pending_approval') {
            return;
        }

        $this->agreement->update([
            'status' => 'active',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    public function cancel(): void
    {
        abort_unless(auth()->user()->can('approve-agreements'), 403);

        if (! in_array($this->agreement->status, ['draft', 'pending_approval'])) {
            return;
        }

        $this->agreement->update(['status' => 'cancelled']);
    }

    public function markDefaulted(): void
    {
        abort_unless(auth()->user()->can('approve-agreements'), 403);

        if ($this->agreement->status !== 'active') {
            return;
        }

        $this->agreement->update(['status' => 'defaulted']);
    }
} ?>

@slot('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $agreement->agreement_number }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $agreement->customer->first_name }} {{ $agreement->customer->last_name }}</p>
        </div>
        <x-status-badge :status="$agreement->status" />
    </div>
@endslot

<div>
    <div class="space-y-6 max-w-5xl">
        <div class="flex flex-wrap items-center gap-3">
            @if ($agreement->status === 'pending_approval')
                @if (auth()->user()->can('approve-agreements'))
                    <button wire:click="approve" wire:confirm="Approve this agreement and activate the schedule?"
                        class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">
                        Approve
                    </button>
                    <button wire:click="cancel" wire:confirm="Cancel this agreement?"
                        class="rounded-lg bg-gray-100 dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200">
                        Cancel
                    </button>
                @endif
            @elseif ($agreement->status === 'active')
                <a href="{{ route('tenant.collections.desk') }}" wire:navigate class="rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                    Collect Payment
                </a>
                @if (auth()->user()->can('approve-agreements'))
                    <button wire:click="markDefaulted" wire:confirm="Mark this agreement as defaulted? This cannot be undone from here."
                        class="rounded-lg bg-rose-50 dark:bg-rose-950/40 px-4 py-2 text-sm font-medium text-rose-600 dark:text-rose-300 hover:bg-rose-100">
                        Mark Defaulted
                    </button>
                @endif
            @endif
            <a href="{{ route('tenant.customers.edit', $agreement->customer) }}" wire:navigate class="text-sm text-walnut-400 hover:text-walnut-400 ml-auto">
                View Customer
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Product</h3>
                    @foreach ($agreement->items as $item)
                        <div class="flex items-center justify-between text-sm py-1.5">
                            <span class="text-gray-700 dark:text-gray-300">{{ $item->product->name }}</span>
                            @if ($item->productSerial)
                                <span class="font-mono text-xs text-gray-500">{{ $item->productSerial->serial_number }}</span>
                            @endif
                            <span class="text-gray-900 dark:text-white font-medium">Rs. {{ number_format((float) $item->unit_price, 2) }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
                    <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Installment Schedule</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium text-gray-500">#</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-500">Due Date</th>
                                    <th class="px-4 py-3 text-right font-medium text-gray-500">Total Due</th>
                                    <th class="px-4 py-3 text-right font-medium text-gray-500">Paid</th>
                                    <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($agreement->schedules as $schedule)
                                    <tr>
                                        <td class="px-4 py-2.5 text-gray-500">{{ $schedule->installment_number }}</td>
                                        <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ $schedule->due_date->format('d M Y') }}</td>
                                        <td class="px-4 py-2.5 text-right text-gray-900 dark:text-white">{{ number_format((float) $schedule->total_due, 2) }}</td>
                                        <td class="px-4 py-2.5 text-right text-gray-500">{{ number_format((float) $schedule->amount_paid, 2) }}</td>
                                        <td class="px-4 py-2.5"><x-status-badge :status="$schedule->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Payment History</h3>
                    <div class="space-y-2">
                        @forelse ($agreement->payments as $payment)
                            <div class="flex items-center justify-between text-sm rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                <span class="text-gray-500">{{ $payment->paid_at->format('d M Y, h:i A') }} · {{ $payment->receipt_number }}</span>
                                <span class="font-medium text-emerald-600 dark:text-emerald-400">Rs. {{ number_format((float) $payment->amount, 2) }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-400">No payments recorded yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-2xl border border-walnut-200/60 dark:border-walnut-900/60 bg-walnut-50/70 dark:bg-walnut-900/40 backdrop-blur-xl shadow-lg p-5 space-y-3">
                    <h3 class="font-semibold text-walnut-900 dark:text-walnut-200">EMI Terms</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Product Price</dt><dd class="text-walnut-900 dark:text-walnut-200">Rs. {{ number_format((float) $agreement->product_price, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Down Payment</dt><dd class="text-walnut-900 dark:text-walnut-200">Rs. {{ number_format((float) $agreement->down_payment, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Interest Rate</dt><dd class="text-walnut-900 dark:text-walnut-200">{{ $agreement->interest_rate }}%</dd></div>
                        <div class="flex justify-between"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Duration</dt><dd class="text-walnut-900 dark:text-walnut-200">{{ $agreement->duration_months }} mo</dd></div>
                        <div class="flex justify-between border-t border-walnut-200 dark:border-walnut-900 pt-2"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Monthly Installment</dt><dd class="font-semibold text-walnut-900 dark:text-walnut-200">Rs. {{ number_format((float) $agreement->monthly_installment, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Outstanding</dt><dd class="font-semibold text-walnut-900 dark:text-walnut-200">Rs. {{ number_format((float) $agreement->outstandingBalance(), 2) }}</dd></div>
                    </dl>
                </div>

                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Guarantors</h3>
                    <div class="space-y-3">
                        @foreach ($agreement->guarantors as $guarantor)
                            <div class="text-sm border-b border-gray-100 dark:border-gray-700 pb-2 last:border-0 last:pb-0">
                                <p class="font-medium text-gray-900 dark:text-white">{{ $guarantor->name }}</p>
                                <p class="text-gray-500 dark:text-gray-400">{{ $guarantor->relation }} · {{ $guarantor->mobile_number }}</p>
                                <p class="text-gray-400 text-xs">{{ $guarantor->cnic_number }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Details</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Salesman</dt><dd class="text-gray-900 dark:text-white">{{ $agreement->salesman?->name ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Start Date</dt><dd class="text-gray-900 dark:text-white">{{ $agreement->start_date?->format('d M Y') ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Approved By</dt><dd class="text-gray-900 dark:text-white">{{ $agreement->approver?->name ?? '—' }}</dd></div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
