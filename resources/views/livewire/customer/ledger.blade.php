<?php

use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.customer')] class extends Component
{
    #[Computed]
    public function entries()
    {
        return Auth::guard('customer')->user()
            ->ledgerEntries()
            ->with([
                'bank',
                // Polymorphic: a Payment for a collection, the Agreement itself
                // for the opening debit. Only Payment carries a bank.
                'reference' => fn ($morphTo) => $morphTo->morphWith([Payment::class => ['bank']]),
            ])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function closingBalance(): string
    {
        return (string) ($this->entries->last()->running_balance ?? '0.00');
    }
} ?>

@slot('header')
    <h1 class="text-xl font-semibold text-gray-900">My Statement</h1>
    <p class="text-sm text-gray-500">Everything charged and paid, most recent last</p>
@endslot

<div class="space-y-5">
    <div class="rounded-2xl border border-black/5 bg-white p-5">
        <p class="text-xs text-gray-500">Closing balance</p>
        <p class="mt-1 text-2xl font-semibold text-gray-900">Rs. {{ number_format((float) $this->closingBalance, 0) }}</p>
        <p class="mt-1 text-xs text-gray-400">
            {{ bccomp($this->closingBalance, '0', 2) > 0 ? 'This is what you still owe.' : 'Nothing outstanding.' }}
        </p>
    </div>

    <div class="overflow-hidden rounded-2xl border border-black/5 bg-white">
        <h2 class="p-4 pb-0 text-sm font-semibold text-gray-900">Statement</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-medium text-gray-500">Date</th>
                        <th class="px-4 py-2.5 text-left font-medium text-gray-500">Description</th>
                        <th class="px-4 py-2.5 text-right font-medium text-gray-500">Charged</th>
                        <th class="px-4 py-2.5 text-right font-medium text-gray-500">Paid</th>
                        <th class="px-4 py-2.5 text-right font-medium text-gray-500">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->entries as $entry)
                        <tr>
                            <td class="px-4 py-2.5 whitespace-nowrap text-gray-500">{{ \Illuminate\Support\Carbon::parse($entry->entry_date)->format('d M Y') }}</td>
                            <td class="px-4 py-2.5 text-gray-700">
                                {{ $entry->description }}
                                @if ($entry->paymentModeLabel())
                                    <span class="text-xs text-gray-400">({{ $entry->paymentModeLabel() }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right text-rose-600">{{ $entry->type === 'debit' ? number_format((float) $entry->amount, 0) : '' }}</td>
                            <td class="px-4 py-2.5 text-right text-emerald-600">{{ $entry->type === 'credit' ? number_format((float) $entry->amount, 0) : '' }}</td>
                            <td class="px-4 py-2.5 text-right font-medium text-gray-900">{{ number_format((float) $entry->running_balance, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Nothing on your statement yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
