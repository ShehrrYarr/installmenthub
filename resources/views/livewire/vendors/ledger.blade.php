<?php

use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Models\VendorLedgerEntry;
use App\Models\VendorLedgerEntryRevision;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public Vendor $vendor;

    public bool $showEntryForm = false;

    /** Set while editing an existing manual entry; null while adding a new one. */
    public ?int $editingEntryId = null;

    /** Entry currently expanded to show its edit history, if any. */
    public ?int $expandedEntryId = null;

    #[Validate('required|in:cash_in,cash_out')]
    public string $entryDirection = 'cash_out';

    #[Validate('required|integer|min:1')]
    public string $entryAmount = '';

    #[Validate('required|in:cash,bank,easypaisa,jazzcash,other')]
    public string $entryPaymentMode = 'cash';

    #[Validate('required|date|before_or_equal:today')]
    public string $entryDate = '';

    #[Validate('required|string|max:255')]
    public string $entryDescription = '';

    public ?int $entryPurchaseOrderId = null;

    public function mount(Vendor $vendor): void
    {
        $this->vendor = $vendor;
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

        $entry = VendorLedgerEntry::where('vendor_id', $this->vendor->id)->findOrFail($entryId);

        abort_unless($entry->isManual(), 403);

        $this->editingEntryId = $entry->id;
        $this->entryDirection = $entry->manual_direction ?? 'cash_out';
        // entryAmount validates as `integer` — the decimal-cast attribute
        // (e.g. "50000.00") fails that rule unless normalized first.
        $this->entryAmount = (string) (int) $entry->amount;
        $this->entryPaymentMode = $entry->payment_mode ?? 'cash';
        $this->entryDate = $entry->entry_date->toDateString();
        $this->entryDescription = (string) $entry->description;
        $this->entryPurchaseOrderId = $entry->purchase_order_id;
        $this->showEntryForm = true;
        $this->expandedEntryId = null;
        $this->resetErrorBag();
    }

    public function toggleHistory(int $entryId): void
    {
        $this->expandedEntryId = $this->expandedEntryId === $entryId ? null : $entryId;
    }

    /**
     * A payment to the vendor (cash_out) nets *toward* what's been paid on a
     * linked PO; a refund/credit note from the vendor (cash_in) nets away
     * from it. $sign is +1 to apply this entry's effect, -1 to undo it.
     */
    private function adjustPurchaseOrderPaidAmount(?int $purchaseOrderId, string $direction, string $amount, int $sign): void
    {
        if (! $purchaseOrderId) {
            return;
        }

        $po = PurchaseOrder::where('vendor_id', $this->vendor->id)->find($purchaseOrderId);

        if (! $po) {
            return;
        }

        $delta = $direction === 'cash_out' ? $amount : bcmul($amount, '-1', 2);
        $delta = $sign === 1 ? $delta : bcmul($delta, '-1', 2);

        $newPaid = bcadd((string) $po->paid_amount, $delta, 2);
        $po->update(['paid_amount' => bccomp($newPaid, '0', 2) === -1 ? '0.00' : $newPaid]);
    }

    public function saveEntry(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $this->validate();

        if ($this->entryPurchaseOrderId && ! $this->purchaseOrders->contains('id', $this->entryPurchaseOrderId)) {
            $this->addError('entryPurchaseOrderId', 'That purchase order does not belong to this vendor.');

            return;
        }

        if ($this->editingEntryId) {
            $entry = VendorLedgerEntry::where('vendor_id', $this->vendor->id)->findOrFail($this->editingEntryId);
            abort_unless($entry->isManual(), 403);

            VendorLedgerEntryRevision::create([
                'vendor_ledger_entry_id' => $entry->id,
                'type' => $entry->type,
                'amount' => $entry->amount,
                'payment_mode' => $entry->payment_mode,
                'purchase_order_id' => $entry->purchase_order_id,
                'manual_direction' => $entry->manual_direction,
                'description' => $entry->description,
                'entry_date' => $entry->entry_date,
                'edited_by' => auth()->id(),
            ]);

            // Undo the old amount's effect on whatever PO it used to be linked to, then apply the new one.
            $this->adjustPurchaseOrderPaidAmount($entry->purchase_order_id, $entry->manual_direction ?? 'cash_out', (string) $entry->amount, -1);
            $this->adjustPurchaseOrderPaidAmount($this->entryPurchaseOrderId, $this->entryDirection, $this->entryAmount, 1);

            $entry->update([
                'type' => 'credit',
                'amount' => $this->entryAmount,
                'payment_mode' => $this->entryPaymentMode,
                'purchase_order_id' => $this->entryPurchaseOrderId,
                'manual_direction' => $this->entryDirection,
                'description' => $this->entryDescription,
                'entry_date' => $this->entryDate,
            ]);

            session()->flash('status', 'Ledger entry updated.');
        } else {
            VendorLedgerEntry::create([
                'vendor_id' => $this->vendor->id,
                'type' => 'credit',
                'amount' => $this->entryAmount,
                'payment_mode' => $this->entryPaymentMode,
                'running_balance' => '0.00',
                'reference_type' => null,
                'reference_id' => null,
                'purchase_order_id' => $this->entryPurchaseOrderId,
                'manual_direction' => $this->entryDirection,
                'description' => $this->entryDescription,
                'entry_date' => $this->entryDate,
                'created_by' => auth()->id(),
            ]);

            $this->adjustPurchaseOrderPaidAmount($this->entryPurchaseOrderId, $this->entryDirection, $this->entryAmount, 1);

            session()->flash('status', 'Ledger entry added.');
        }

        VendorLedgerEntry::recalculateFor($this->vendor->id);

        unset($this->entries, $this->purchaseOrders, $this->summary);
        $this->editingEntryId = null;
        $this->resetEntryFields();
        $this->showEntryForm = false;
    }

    private function resetEntryFields(): void
    {
        $this->reset(['entryAmount', 'entryDescription', 'entryPurchaseOrderId']);
        $this->entryDirection = 'cash_out';
        $this->entryPaymentMode = 'cash';
        $this->entryDate = now()->toDateString();
    }

    #[Computed]
    public function purchaseOrders()
    {
        return $this->vendor->purchaseOrders()->latest('order_date')->get();
    }

    #[Computed]
    public function entries()
    {
        return $this->vendor->ledgerEntries()
            ->with(['revisions.editor', 'reference', 'purchaseOrder'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
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
            <x-stat-card label="Total Purchased" :value="'Rs. '.number_format((float) $this->summary['total_purchased'], 0)" color="sky" />
            <x-stat-card label="Balance Owed" :value="'Rs. '.number_format((float) $this->summary['current_balance'], 0)" color="rose" hint="Positive = shop owes vendor" />
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
                <form wire:submit="saveEntry" class="mx-5 mt-4 rounded-xl bg-gray-50 dark:bg-gray-900/40 p-4 space-y-4" wire:loading.class="opacity-50 pointer-events-none" wire:target="saveEntry">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $editingEntryId ? 'Edit Entry' : 'New Entry' }}</p>
                    <div class="flex gap-2">
                        <button type="button" wire:click="$set('entryDirection', 'cash_out')"
                            class="flex-1 rounded-lg px-4 py-2 text-sm font-medium border {{ $entryDirection === 'cash_out' ? 'bg-rose-600 text-white border-rose-600' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600' }}">
                            Cash Out <span class="font-normal opacity-80">(payment to vendor)</span>
                        </button>
                        <button type="button" wire:click="$set('entryDirection', 'cash_in')"
                            class="flex-1 rounded-lg px-4 py-2 text-sm font-medium border {{ $entryDirection === 'cash_in' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600' }}">
                            Cash In <span class="font-normal opacity-80">(refund from vendor)</span>
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
                            <x-text-input wire:model="entryDescription" placeholder="e.g. Paid off PO-260906-1234 balance" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('entryDescription')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label value="Purchase Order (optional)" />
                            <select wire:model="entryPurchaseOrderId" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                                <option value="">— Not tied to a specific order —</option>
                                @foreach ($this->purchaseOrders as $po)
                                    <option value="{{ $po->id }}">{{ $po->po_number }} — Rs. {{ number_format((float) $po->total_amount, 0) }} (Rs. {{ number_format((float) $po->paid_amount, 0) }} paid)</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-400">If picked, this also updates that order's paid amount.</p>
                            <x-input-error :messages="$errors->get('entryPurchaseOrderId')" class="mt-1" />
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveEntry"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-walnut-600 px-5 py-2 text-sm font-medium text-white hover:bg-walnut-400 disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg wire:loading wire:target="saveEntry" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span wire:loading.remove wire:target="saveEntry">{{ $editingEntryId ? 'Update Entry' : 'Save Entry' }}</span>
                            <span wire:loading wire:target="saveEntry">{{ $editingEntryId ? 'Updating…' : 'Saving…' }}</span>
                        </button>
                        <button type="button" wire:click="toggleEntryForm" wire:loading.attr="disabled" wire:target="saveEntry"
                            class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 disabled:opacity-50 disabled:cursor-not-allowed">
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
                                    @if ($entry->purchaseOrder)
                                        <span class="text-xs text-gray-400">({{ $entry->purchaseOrder->po_number }})</span>
                                    @endif
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
                                                        Previously: {{ $revision->manual_direction === 'cash_in' ? 'Cash In' : 'Cash Out' }} of
                                                        Rs. {{ number_format((float) $revision->amount, 0) }}
                                                        @if ($revision->payment_mode) ({{ ucfirst($revision->payment_mode) }}) @endif
                                                        on {{ $revision->entry_date->format('d M Y') }} — "{{ $revision->description }}"
                                                    </span>
                                                </div>
                                            @endforeach
                                            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-white/70 dark:bg-gray-900/40 px-3 py-2">
                                                <span class="text-gray-400">Now</span>
                                                <span class="ml-auto">
                                                    Currently: {{ $entry->manual_direction === 'cash_in' ? 'Cash In' : 'Cash Out' }} of
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
