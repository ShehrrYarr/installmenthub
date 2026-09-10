<?php

use App\Models\Agreement;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Support\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public string $query = '';

    public ?int $selectedAgreementId = null;

    public string $amount = '';

    public string $paymentMode = PaymentMethod::CASH;

    public ?int $bankId = null;

    public string $referenceNumber = '';

    public string $notes = '';

    public ?int $lastPaymentId = null;

    // Arriving from the Collection Book's "Pay" link (?agreement=&amount=) —
    // kept as their own properties (rather than reusing $selectedAgreementId/
    // $amount directly as #[Url]) so that typing in the amount field during
    // normal use doesn't keep rewriting the address bar.
    #[Url]
    public ?int $agreement = null;

    #[Url]
    public ?string $prefillAmount = null;

    public function mount(): void
    {
        if (! $this->agreement) {
            return;
        }

        $target = Agreement::whereIn('status', ['active', 'pending_approval'])->find($this->agreement);

        if ($target) {
            $this->selectedAgreementId = $target->id;
            $this->amount = $this->prefillAmount ?: (string) ($target->schedules()
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->orderBy('installment_number')
                ->first()?->balanceRemaining() ?? '');
        }

        // One-time use — clear both so a page refresh doesn't keep re-forcing
        // this agreement/amount back in after the collector has moved on,
        // and so the URL tidies itself up on the next render.
        $this->agreement = null;
        $this->prefillAmount = null;
    }

    public function search(): void
    {
        $this->selectedAgreementId = null;
    }

    public function getResultsProperty()
    {
        if (mb_strlen($this->query) < 2) {
            return collect();
        }

        return Agreement::query()
            ->with('customer')
            ->whereIn('status', ['active', 'pending_approval'])
            ->where(function ($q) {
                $q->where('agreement_number', 'like', "%{$this->query}%")
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('cnic_number', 'like', "%{$this->query}%")
                        ->orWhere('phone', 'like', "%{$this->query}%")
                        ->orWhere('first_name', 'like', "%{$this->query}%")
                        ->orWhere('last_name', 'like', "%{$this->query}%"))
                    ->orWhereHas('items.productSerial', fn ($s) => $s
                        ->where('serial_number', 'like', "%{$this->query}%"));
            })
            ->limit(10)
            ->get();
    }

    public function select(int $agreementId): void
    {
        $this->selectedAgreementId = $agreementId;
        $this->query = '';

        $next = $this->selectedAgreement?->schedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('installment_number')
            ->first();

        $this->amount = $next ? (string) $next->balanceRemaining() : '';
    }

    public function getSelectedAgreementProperty(): ?Agreement
    {
        return $this->selectedAgreementId
            ? Agreement::with(['customer', 'items.product', 'schedules' => fn ($q) => $q->orderBy('installment_number')])->find($this->selectedAgreementId)
            : null;
    }

    public function postPayment(): void
    {
        $this->validate([
            'amount' => 'required|numeric|min:0.01',
            'paymentMode' => PaymentMethod::methodRule(),
            'bankId' => PaymentMethod::bankRule('paymentMode'),
        ], PaymentMethod::bankMessages('bankId'));

        $agreement = $this->selectedAgreement;

        if (! $agreement) {
            $this->addError('amount', 'Select an agreement first.');

            return;
        }

        $outstanding = $agreement->schedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get()
            ->reduce(fn ($carry, $schedule) => bcadd($carry, $schedule->balanceRemaining(), 2), '0.00');

        if (bccomp($this->amount, $outstanding, 2) > 0) {
            $this->addError('amount', 'Amount exceeds the outstanding balance of Rs. '.number_format((float) $outstanding, 0).'.');

            return;
        }

        [$paymentMode, $bankId] = PaymentMethod::toStorage($this->paymentMode, $this->bankId);

        $payment = DB::transaction(function () use ($agreement, $paymentMode, $bankId) {
            $remaining = $this->amount;

            $schedules = $agreement->schedules()
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->orderBy('installment_number')
                ->get();

            $firstSchedule = $schedules->first();

            $payment = Payment::create([
                'agreement_id' => $agreement->id,
                'installment_schedule_id' => $firstSchedule?->id,
                'customer_id' => $agreement->customer_id,
                'amount' => $this->amount,
                'payment_mode' => $paymentMode,
                'bank_id' => $bankId,
                'reference_number' => $this->referenceNumber ?: null,
                'received_by' => auth()->id(),
                'receipt_number' => 'RCP-'.$agreement->shop_id.'-'.now()->format('ymd').'-'.random_int(1000, 9999),
                'paid_at' => now(),
                'notes' => $this->notes ?: null,
            ]);

            foreach ($schedules as $schedule) {
                if (bccomp($remaining, '0', 2) <= 0) {
                    break;
                }

                $due = $schedule->balanceRemaining();
                $apply = bccomp($remaining, $due, 2) >= 0 ? $due : $remaining;

                $schedule->amount_paid = bcadd($schedule->amount_paid, $apply, 2);
                if (bccomp($schedule->amount_paid, $schedule->total_due, 2) >= 0) {
                    $schedule->status = 'paid';
                    $schedule->paid_at = now();
                } else {
                    // A partially-paid installment that's already past its due date
                    // stays 'overdue' (not 'partial') so dashboards/penalties still
                    // flag it until it's paid in full — otherwise it'd silently drop
                    // off overdue lists until the next day's penalty sweep re-flags it.
                    $schedule->status = $schedule->due_date->isPast() ? 'overdue' : 'partial';
                }
                $schedule->save();

                $remaining = bcsub($remaining, $apply, 2);
            }

            $lastBalance = CustomerLedgerEntry::where('customer_id', $agreement->customer_id)->latest('id')->value('running_balance') ?? '0.00';

            CustomerLedgerEntry::create([
                'customer_id' => $agreement->customer_id,
                'agreement_id' => $agreement->id,
                'type' => 'credit',
                'amount' => $this->amount,
                'running_balance' => bcsub($lastBalance, $this->amount, 2),
                'reference_type' => Payment::class,
                'reference_id' => $payment->id,
                'description' => "Installment payment — {$agreement->ledgerLabel()}",
                'entry_date' => now()->toDateString(),
                'created_by' => auth()->id(),
            ]);

            if ($agreement->schedules()->whereIn('status', ['pending', 'partial', 'overdue'])->doesntExist()) {
                $agreement->update(['status' => 'completed']);
            }

            return $payment;
        });

        $this->lastPaymentId = $payment->id;
        $this->reset(['amount', 'referenceNumber', 'notes']);
        $this->selectedAgreementId = $agreement->id;

        // The agreement (and its eager-loaded schedules) was cached by #[Computed]
        // before this payment updated them — bust the cache so the render picks up
        // the fresh statuses/balances instead of the pre-payment snapshot.
        unset($this->selectedAgreement);

        session()->flash('status', "Payment of Rs. {$payment->amount} recorded — receipt {$payment->receipt_number}.");
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Collection Desk</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Search by agreement no, CNIC, phone, or serial/IMEI</p>
    @endslot

<div>

    @if (session('status'))
        <div class="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 space-y-3">
            <x-search-input model="query" :debounce="250" placeholder="Agreement No / CNIC / Phone / Serial…" />

            <div class="space-y-2" wire:loading.class="opacity-50 pointer-events-none" wire:target="query">
                @forelse ($this->results as $result)
                    <button type="button" wire:click="select({{ $result->id }})"
                        class="w-full text-left rounded-xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur p-3 hover:border-walnut-200">
                        <div class="flex items-center justify-between">
                            <p class="font-medium text-gray-900 dark:text-white text-sm">{{ $result->agreement_number }}</p>
                            <x-status-badge :status="$result->status" />
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $result->customer->first_name }} {{ $result->customer->last_name }} · {{ $result->customer->phone }}</p>
                    </button>
                @empty
                    @if (mb_strlen($query) >= 2)
                        <p class="text-sm text-gray-400 px-1">No matching agreements.</p>
                    @endif
                @endforelse
            </div>
        </div>

        <div class="lg:col-span-2">
            @if ($this->selectedAgreement)
                @php($agreement = $this->selectedAgreement)
                <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $agreement->agreement_number }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $agreement->customer->first_name }} {{ $agreement->customer->last_name }} · {{ $agreement->customer->cnic_number }}</p>
                        </div>
                        <x-status-badge :status="$agreement->status" />
                    </div>

                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900/50 p-3">
                            <p class="text-xs text-gray-400">Monthly Installment</p>
                            <p class="font-semibold text-gray-900 dark:text-white">Rs. {{ number_format((float) $agreement->monthly_installment, 0) }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900/50 p-3">
                            <p class="text-xs text-gray-400">Outstanding</p>
                            <p class="font-semibold text-gray-900 dark:text-white">Rs. {{ number_format((float) $agreement->outstandingBalance(), 0) }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900/50 p-3">
                            <p class="text-xs text-gray-400">Duration</p>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $agreement->duration_months }} mo</p>
                        </div>
                    </div>

                    <form wire:submit="postPayment" class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-gray-100 dark:border-gray-700 pt-4"
                          wire:loading.class="opacity-50 pointer-events-none" wire:target="postPayment">
                        <div>
                            <x-input-label for="amount" value="Amount Received" />
                            <x-text-input id="amount" type="number" step="0.01" wire:model="amount" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                        </div>
                        <x-payment-method-select method-model="paymentMode" bank-model="bankId" label="Payment Mode" />
                        <div>
                            <x-input-label for="referenceNumber" value="Reference # (optional)" />
                            <x-text-input id="referenceNumber" wire:model="referenceNumber" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="notes" value="Notes (optional)" />
                            <x-text-input id="notes" wire:model="notes" class="mt-1 block w-full" />
                        </div>

                        <div class="sm:col-span-2 flex items-center gap-3">
                            <button type="submit" wire:loading.attr="disabled" wire:target="postPayment"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed">
                                <svg wire:loading wire:target="postPayment" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span wire:loading.remove wire:target="postPayment">Post Payment</span>
                                <span wire:loading wire:target="postPayment">Posting…</span>
                            </button>

                            @if ($lastPaymentId)
                                <a href="{{ \Illuminate\Support\Facades\Route::has('receipts.thermal') ? route('receipts.thermal', $lastPaymentId) : '#' }}"
                                   target="_blank"
                                   class="inline-flex items-center gap-2 rounded-lg bg-gray-100 dark:bg-gray-700 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200">
                                    <x-tenant-icon name="printer" class="h-4 w-4" /> Print Receipt
                                </a>
                            @endif
                        </div>
                    </form>

                    <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Schedule</p>
                        <div class="space-y-1.5 max-h-64 overflow-y-auto pr-1">
                            @foreach ($agreement->schedules as $schedule)
                                <div class="flex items-center justify-between text-sm rounded-lg px-3 py-2 bg-gray-50 dark:bg-gray-900/40">
                                    <span class="text-gray-500">#{{ $schedule->installment_number }} · {{ $schedule->due_date->format('d M Y') }}</span>
                                    <span class="text-gray-700 dark:text-gray-300">Rs. {{ number_format((float) $schedule->total_due, 0) }}</span>
                                    <x-status-badge :status="$schedule->status" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 p-12 text-center text-gray-400">
                    Search and select an agreement to collect a payment.
                </div>
            @endif
        </div>
    </div>
</div>
