<?php

use App\Models\ProductSerial;
use App\Models\PurchaseOrder;
use App\Models\VendorLedgerEntry;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public PurchaseOrder $purchaseOrder;

    /** @var array<int, array<int, string>> */
    public array $serials = [];

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        abort_unless(auth()->user()->can('manage-purchase-orders'), 403);

        $this->purchaseOrder = $purchaseOrder->load(['items.product', 'vendor']);

        foreach ($this->purchaseOrder->items as $item) {
            if ($item->product->is_serialized) {
                $this->serials[$item->id] = array_fill(0, $item->quantity, '');
            }
        }
    }

    public function receive(): void
    {
        if ($this->purchaseOrder->status !== 'draft') {
            return;
        }

        $rules = [];
        foreach ($this->purchaseOrder->items as $item) {
            if ($item->product->is_serialized) {
                $rules["serials.{$item->id}.*"] = 'required|string|max:100|distinct';
            }
        }
        $this->validate($rules);

        $allSerials = collect($this->serials)->flatten()->filter()->values();
        $duplicates = ProductSerial::whereIn('serial_number', $allSerials)->pluck('serial_number');

        if ($duplicates->isNotEmpty()) {
            $this->addError('serials', 'Already registered elsewhere: '.$duplicates->join(', '));

            return;
        }

        DB::transaction(function () {
            // Re-check status under a row lock: two near-simultaneous submits could
            // both pass the draft check above before either transaction commits,
            // which would double the stock increment and vendor ledger entries.
            $locked = PurchaseOrder::whereKey($this->purchaseOrder->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'draft') {
                return;
            }

            foreach ($this->purchaseOrder->items as $item) {
                if ($item->product->is_serialized) {
                    foreach ($this->serials[$item->id] as $serial) {
                        ProductSerial::create([
                            'product_id' => $item->product_id,
                            'purchase_order_item_id' => $item->id,
                            'serial_number' => $serial,
                            'status' => 'in_stock',
                        ]);
                    }
                }

                $item->product->increment('stock_quantity', $item->quantity);
            }

            $this->purchaseOrder->update([
                'status' => 'received',
                'paid_amount' => $this->purchaseOrder->payment_mode === 'credit' ? 0 : $this->purchaseOrder->total_amount,
            ]);

            $lastBalance = VendorLedgerEntry::where('vendor_id', $this->purchaseOrder->vendor_id)->latest('id')->value('running_balance') ?? '0.00';
            $running = bcadd($lastBalance, $this->purchaseOrder->total_amount, 2);

            VendorLedgerEntry::create([
                'vendor_id' => $this->purchaseOrder->vendor_id,
                'type' => 'debit',
                'amount' => $this->purchaseOrder->total_amount,
                'running_balance' => $running,
                'reference_type' => PurchaseOrder::class,
                'reference_id' => $this->purchaseOrder->id,
                'description' => "Purchase invoice {$this->purchaseOrder->po_number}",
                'entry_date' => now()->toDateString(),
                'created_by' => auth()->id(),
            ]);

            if ($this->purchaseOrder->payment_mode !== 'credit') {
                $running = bcsub($running, $this->purchaseOrder->total_amount, 2);

                VendorLedgerEntry::create([
                    'vendor_id' => $this->purchaseOrder->vendor_id,
                    'type' => 'credit',
                    'amount' => $this->purchaseOrder->total_amount,
                    'running_balance' => $running,
                    'reference_type' => PurchaseOrder::class,
                    'reference_id' => $this->purchaseOrder->id,
                    'description' => "Payment on receipt — {$this->purchaseOrder->po_number}",
                    'entry_date' => now()->toDateString(),
                    'created_by' => auth()->id(),
                ]);
            }
        });

        $this->purchaseOrder->refresh();
        session()->flash('status', 'Stock received and serial numbers registered.');
    }
} ?>

@slot('header')
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $purchaseOrder->po_number }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $purchaseOrder->vendor->name }} · {{ $purchaseOrder->order_date->format('d M Y') }}</p>
            </div>
            <x-status-badge :status="$purchaseOrder->status" />
        </div>
    @endslot

<div>

    <div class="max-w-3xl space-y-6">
        @if ($purchaseOrder->status === 'draft')
            <div class="rounded-2xl border border-amber-200/60 dark:border-amber-900/60 bg-amber-50/60 dark:bg-amber-950/30 p-4 text-sm text-amber-800 dark:text-amber-200">
                Enter a unique IMEI/serial number for every serialized unit before marking this order received. Non-serialized items are received by quantity only.
            </div>
        @endif

        <form wire:submit="receive" class="space-y-4">
            @foreach ($purchaseOrder->items as $item)
                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <p class="font-semibold text-gray-900 dark:text-white">{{ $item->product->name }}</p>
                        <p class="text-sm text-gray-500">Qty {{ $item->quantity }} · Rs. {{ number_format((float) $item->cost_price, 0) }} each</p>
                    </div>

                    @if ($item->product->is_serialized)
                        @if ($purchaseOrder->status === 'draft')
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @foreach ($serials[$item->id] as $i => $value)
                                    <x-text-input placeholder="Serial / IMEI #{{ $i + 1 }}" wire:model="serials.{{ $item->id }}.{{ $i }}" class="block w-full text-sm" />
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-wrap gap-2">
                                @foreach ($item->serials as $serial)
                                    <span class="rounded-full bg-gray-100 dark:bg-gray-700 px-3 py-1 text-xs font-mono text-gray-600 dark:text-gray-300">{{ $serial->serial_number }}</span>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="text-xs text-gray-400">Non-serialized — tracked by quantity only.</p>
                    @endif
                </div>
            @endforeach

            <x-input-error :messages="$errors->get('serials')" class="mt-1" />

            @if ($purchaseOrder->status === 'draft')
                <button type="submit" wire:loading.attr="disabled" wire:target="receive" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-60">
                    Mark as Received
                </button>
            @endif
        </form>
    </div>
</div>
