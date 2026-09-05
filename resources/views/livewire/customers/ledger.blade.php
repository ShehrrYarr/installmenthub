<?php

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerLedgerEntryRevision;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public Customer $customer;

    public bool $showEntryForm = false;

    /** Set while editing an existing manual entry; null while adding a new one. */
    public ?int $editingEntryId = null;

    /** Entry currently expanded to show its edit history, if any. */
    public ?int $expandedEntryId = null;

    #[Validate('required|in:cash_in,cash_out')]
    public string $entryDirection = 'cash_in';

    #[Validate('required|integer|min:1')]
    public string $entryAmount = '';

    #[Validate('required|in:cash,bank,easypaisa,jazzcash,other')]
    public string $entryPaymentMode = 'cash';

    #[Validate('required|date|before_or_equal:today')]
    public string $entryDate = '';

    #[Validate('required|string|max:255')]
    public string $entryDescription = '';

    public ?int $entryAgreementId = null;

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
        $this->entryDate = now()->toDateString();
    }

    public function toggleEntryForm(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $this->showEntryForm = ! $this->showEntryForm;
        $this->editingEntryId = null;
        $this->resetEntryFields();
        $this->resetErrorBag();
    }

    public function startEdit(int $entryId): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $entry = CustomerLedgerEntry::where('customer_id', $this->customer->id)->findOrFail($entryId);

        abort_unless($entry->isManual(), 403);

        $this->editingEntryId = $entry->id;
        $this->entryDirection = $entry->type === 'credit' ? 'cash_in' : 'cash_out';
        $this->entryAmount = (string) $entry->amount;
        $this->entryPaymentMode = $entry->payment_mode ?? 'cash';
        $this->entryDate = $entry->entry_date->toDateString();
        $this->entryDescription = (string) $entry->description;
        $this->entryAgreementId = $entry->agreement_id;
        $this->showEntryForm = true;
        $this->expandedEntryId = null;
        $this->resetErrorBag();
    }

    public function toggleHistory(int $entryId): void
    {
        $this->expandedEntryId = $this->expandedEntryId === $entryId ? null : $entryId;
    }

    public function saveEntry(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $this->validate();

        if ($this->entryAgreementId && ! $this->agreements->contains('id', $this->entryAgreementId)) {
            $this->addError('entryAgreementId', 'That agreement does not belong to this customer.');

            return;
        }

        $type = $this->entryDirection === 'cash_in' ? 'credit' : 'debit';

        if ($this->editingEntryId) {
            $entry = CustomerLedgerEntry::where('customer_id', $this->customer->id)->findOrFail($this->editingEntryId);
            abort_unless($entry->isManual(), 403);

            CustomerLedgerEntryRevision::create([
                'customer_ledger_entry_id' => $entry->id,
                'type' => $entry->type,
                'amount' => $entry->amount,
                'payment_mode' => $entry->payment_mode,
                'agreement_id' => $entry->agreement_id,
                'description' => $entry->description,
                'entry_date' => $entry->entry_date,
                'edited_by' => auth()->id(),
            ]);

            $entry->update([
                'agreement_id' => $this->entryAgreementId,
                'type' => $type,
                'amount' => $this->entryAmount,
                'payment_mode' => $this->entryPaymentMode,
                'description' => $this->entryDescription,
                'entry_date' => $this->entryDate,
            ]);

            session()->flash('status', 'Ledger entry updated.');
        } else {
            CustomerLedgerEntry::create([
                'customer_id' => $this->customer->id,
                'agreement_id' => $this->entryAgreementId,
                'type' => $type,
                'amount' => $this->entryAmount,
                'payment_mode' => $this->entryPaymentMode,
                'running_balance' => '0.00',
                'reference_type' => null,
                'reference_id' => null,
                'description' => $this->entryDescription,
                'entry_date' => $this->entryDate,
                'created_by' => auth()->id(),
            ]);

            session()->flash('status', 'Ledger entry added.');
        }

        CustomerLedgerEntry::recalculateFor($this->customer->id);

        unset($this->entries);
        $this->editingEntryId = null;
        $this->resetEntryFields();
        $this->showEntryForm = false;
    }

    private function resetEntryFields(): void
    {
        $this->reset(['entryAmount', 'entryDescription', 'entryAgreementId']);
        $this->entryDirection = 'cash_in';
        $this->entryPaymentMode = 'cash';
        $this->entryDate = now()->toDateString();
    }

    #[Computed]
    public function agreements()
    {
        return $this->customer->agreements()->withCount('schedules')->latest()->get();
    }

    #[Computed]
    public function entries()
    {
        return $this->customer->ledgerEntries()
            ->with(['revisions.editor', 'reference'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
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
            <x-stat-card label="Down Payments" :value="'Rs. '.number_format((float) $this->summary['down_payments'], 0)" color="sky" />
            <x-stat-card label="Interest Charged" :value="'Rs. '.number_format((float) $this->summary['interest_charged'], 0)" color="amber" />
            <x-stat-card label="Collected" :value="'Rs. '.number_format((float) $this->summary['collected'], 0)" color="emerald" />
            <x-stat-card label="Penalties Levied" :value="'Rs. '.number_format((float) $this->summary['penalties'], 0)" color="rose" />
            <x-stat-card label="Net Outstanding" :value="'Rs. '.number_format((float) $this->summary['outstanding'], 0)" color="walnut" />
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Agreements</h3>
            <div class="space-y-2">
                @forelse ($this->agreements as $agreement)
                    <a href="{{ route('tenant.agreements.show', $agreement) }}" wire:navigate class="flex items-center justify-between rounded-xl bg-gray-50 dark:bg-gray-900/40 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-900/70">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $agreement->agreement_number }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Rs. {{ number_format((float) $agreement->monthly_installment, 0) }}/mo · {{ $agreement->duration_months }} mo</p>
                        </div>
                        <x-status-badge :status="$agreement->status" />
                    </a>
                @empty
                    <p class="text-sm text-gray-400">No agreements yet.</p>
                @endforelse
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
            <div class="flex items-center justify-between p-5 pb-0">
                <h3 class="font-semibold text-gray-900 dark:text-white">Ledger</h3>
                @if (auth()->user()->hasRole('Shop Admin'))
                    <button type="button" wire:click="toggleEntryForm"
                        class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium
                               {{ $showEntryForm
                                    ? 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'
                                    : 'bg-walnut-600 text-white hover:bg-walnut-400' }}">
                        @unless ($showEntryForm)
                            <x-tenant-icon name="plus" class="h-4 w-4" />
                        @endunless
                        {{ $showEntryForm ? 'Cancel' : 'Add Entry' }}
                    </button>
                @endif
            </div>

            @if ($showEntryForm)
                <form wire:submit="saveEntry" class="mx-5 mt-4 rounded-xl bg-gray-50 dark:bg-gray-900/40 p-4 space-y-4">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $editingEntryId ? 'Edit Entry' : 'New Entry' }}</p>
                    <div class="flex gap-2">
                        <button type="button" wire:click="$set('entryDirection', 'cash_in')"
                            class="flex-1 rounded-lg px-4 py-2 text-sm font-medium border {{ $entryDirection === 'cash_in' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600' }}">
                            Cash In
                        </button>
                        <button type="button" wire:click="$set('entryDirection', 'cash_out')"
                            class="flex-1 rounded-lg px-4 py-2 text-sm font-medium border {{ $entryDirection === 'cash_out' ? 'bg-rose-600 text-white border-rose-600' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600' }}">
                            Cash Out
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label value="Amount" />
                            <x-text-input type="number" step="1" wire:model="entryAmount" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('entryAmount')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label value="Date" />
                            <x-text-input type="date" wire:model="entryDate" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('entryDate')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label value="Payment Method" />
                            <select wire:model="entryPaymentMode" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="easypaisa">EasyPaisa</option>
                                <option value="jazzcash">JazzCash</option>
                                <option value="other">Other</option>
                            </select>
                            <x-input-error :messages="$errors->get('entryPaymentMode')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label value="Description" />
                            <x-text-input wire:model="entryDescription" placeholder="e.g. Cash collected before switching to this system" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('entryDescription')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label value="Agreement (optional)" />
                            <select wire:model="entryAgreementId" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                                <option value="">— No specific agreement —</option>
                                @foreach ($this->agreements as $agreement)
                                    <option value="{{ $agreement->id }}">{{ $agreement->agreement_number }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('entryAgreementId')" class="mt-1" />
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" class="rounded-lg bg-walnut-600 px-5 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                            {{ $editingEntryId ? 'Update Entry' : 'Save Entry' }}
                        </button>
                        <button type="button" wire:click="toggleEntryForm" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700">
                            Cancel
                        </button>
                    </div>
                </form>
            @endif

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Date</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Description</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Debit</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Credit</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Balance</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($this->entries as $entry)
                            <tr>
                                <td class="px-4 py-2.5 text-gray-500">{{ \Illuminate\Support\Carbon::parse($entry->entry_date)->format('d M Y') }}</td>
                                <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                    {{ $entry->description }}
                                    @if ($entry->paymentModeLabel())
                                        <span class="text-xs text-gray-400">({{ $entry->paymentModeLabel() }})</span>
                                    @endif
                                    @if ($entry->isManual())
                                        <span class="ml-1.5 inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">Manual</span>
                                    @endif
                                    @if ($entry->revisions->isNotEmpty())
                                        <button type="button" wire:click="toggleHistory({{ $entry->id }})" class="ml-1.5 inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/40 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300 hover:bg-amber-200">
                                            Edited
                                        </button>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right text-rose-600">{{ $entry->type === 'debit' ? number_format((float) $entry->amount, 0) : '' }}</td>
                                <td class="px-4 py-2.5 text-right text-emerald-600">{{ $entry->type === 'credit' ? number_format((float) $entry->amount, 0) : '' }}</td>
                                <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ number_format((float) $entry->running_balance, 0) }}</td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    @if ($entry->isManual() && auth()->user()->hasRole('Shop Admin'))
                                        <button type="button" wire:click="startEdit({{ $entry->id }})" class="text-walnut-400 hover:text-walnut-400 font-medium text-xs">Edit</button>
                                    @endif
                                </td>
                            </tr>
                            @if ($expandedEntryId === $entry->id)
                                <tr>
                                    <td colspan="6" class="px-4 py-3 bg-amber-50/60 dark:bg-amber-950/20">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300 mb-2">Edit History</p>
                                        <div class="space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                                            @foreach ($entry->revisions as $revision)
                                                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-white/70 dark:bg-gray-900/40 px-3 py-2">
                                                    <span class="text-gray-400">{{ $revision->created_at->format('d M Y, g:i A') }}</span>
                                                    <span>by {{ $revision->editor?->name ?? 'Unknown' }}</span>
                                                    <span class="ml-auto">
                                                        Previously: {{ $revision->type === 'debit' ? 'Cash Out' : 'Cash In' }} of
                                                        Rs. {{ number_format((float) $revision->amount, 0) }}
                                                        @if ($revision->payment_mode) ({{ ucfirst($revision->payment_mode) }}) @endif
                                                        on {{ $revision->entry_date->format('d M Y') }} — "{{ $revision->description }}"
                                                    </span>
                                                </div>
                                            @endforeach
                                            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-white/70 dark:bg-gray-900/40 px-3 py-2">
                                                <span class="text-gray-400">Now</span>
                                                <span class="ml-auto">
                                                    Currently: {{ $entry->type === 'debit' ? 'Cash Out' : 'Cash In' }} of
                                                    Rs. {{ number_format((float) $entry->amount, 0) }}
                                                    @if ($entry->payment_mode) ({{ ucfirst($entry->payment_mode) }}) @endif
                                                    on {{ $entry->entry_date->format('d M Y') }} — "{{ $entry->description }}"
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No ledger activity yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
