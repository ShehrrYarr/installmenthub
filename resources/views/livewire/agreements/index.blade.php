<?php

use App\Models\Agreement;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithPagination;

    public string $status = 'all';

    public function approve(int $agreementId): void
    {
        abort_unless(auth()->user()->can('approve-agreements'), 403);

        $agreement = Agreement::findOrFail($agreementId);

        if ($agreement->status !== 'pending_approval') {
            return;
        }

        $agreement->update([
            'status' => 'active',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    public function with(): array
    {
        $agreements = Agreement::query()
            ->with('customer')
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(12);

        return ['agreements' => $agreements];
    }
} ?>

@slot('header')
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Agreements</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Installment agreements and their lifecycle</p>
            </div>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.agreements.create') ? route('tenant.agreements.create') : '#' }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                <x-tenant-icon name="plus" class="h-4 w-4" /> New Agreement
            </a>
        </div>
    @endslot

<div>

    @if (session('status'))
        <div class="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="space-y-4">
        <select wire:model.live="status" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm">
            <option value="all">All statuses</option>
            <option value="draft">Draft</option>
            <option value="pending_approval">Pending Approval</option>
            <option value="active">Active</option>
            <option value="completed">Completed</option>
            <option value="defaulted">Defaulted</option>
            <option value="cancelled">Cancelled</option>
        </select>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse ($agreements as $agreement)
                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-3">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $agreement->agreement_number }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $agreement->customer->first_name }} {{ $agreement->customer->last_name }}</p>
                        </div>
                        <x-status-badge :status="$agreement->status" />
                    </div>

                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2">
                            <dt class="text-xs text-gray-400">Monthly</dt>
                            <dd class="font-medium text-gray-900 dark:text-white">Rs. {{ number_format((float) $agreement->monthly_installment, 0) }}</dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-2">
                            <dt class="text-xs text-gray-400">Outstanding</dt>
                            <dd class="font-medium text-gray-900 dark:text-white">Rs. {{ number_format((float) $agreement->outstandingBalance(), 0) }}</dd>
                        </div>
                    </dl>

                    <div class="flex items-center gap-2">
                        <a href="{{ route('tenant.agreements.show', $agreement) }}" wire:navigate
                           class="flex-1 text-center rounded-lg bg-gray-100 dark:bg-gray-700 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200">
                            View
                        </a>
                        @if ($agreement->status === 'pending_approval' && auth()->user()->can('approve-agreements'))
                            <button wire:click="approve({{ $agreement->id }})" wire:confirm="Approve this agreement and activate the schedule?"
                                class="flex-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500">
                                Approve
                            </button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center text-gray-400">
                    No agreements found.
                </div>
            @endforelse
        </div>

        {{ $agreements->links() }}
    </div>
</div>
