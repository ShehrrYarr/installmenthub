<?php

use App\Models\Sale;
use App\Support\PaymentMethod;
use App\Support\PosCalculator;
use App\Support\PosReturns;
use App\Support\PosSalePayments;
use App\Support\ReceiptProof;
use App\Support\Tenant;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithFileUploads;

    public Sale $sale;

    /** A message for the action just taken (the flash message covers arriving here from the register). */
    public string $notice = '';

    // ── Receive a payment ──────────────────────────────────────────────

    public bool $showPaymentForm = false;

    public string $payAmount = '';

    public string $payMethod = PaymentMethod::CASH;

    public $payBankId = null;

    public string $payReference = '';

    public string $payTendered = '';

    public $payProof = null;

    // ── Take goods back ────────────────────────────────────────────────

    public bool $showReturnForm = false;

    /** Units to return, keyed by line group (see Sale::returnableGroups()). @var array<int, string> */
    public array $returnQty = [];

    public string $refundMode = PaymentMethod::CASH;

    public $refundBankId = null;

    public string $refundReference = '';

    public $refundProof = null;

    public string $returnNotes = '';

    public function mount(Sale $sale): void
    {
        abort_unless(Tenant::current()?->posEnabled(), 404);

        $this->sale = $this->fresh($sale->id);
    }

    private function fresh(int $id): Sale
    {
        return Sale::with([
            'shop', 'customer', 'seller', 'items', 'payments.bank', 'payments.media', 'payments.receiver',
            'returns.items.saleItem', 'returns.bank', 'returns.returner', 'returns.media',
        ])->findOrFail($id);
    }

    private function refreshSale(): void
    {
        $this->sale = $this->fresh($this->sale->id);
        unset($this->returnQuote);
    }

    #[Computed]
    public function banks()
    {
        return PaymentMethod::banks();
    }

    // ── Receive a payment ──────────────────────────────────────────────

    public function togglePaymentForm(): void
    {
        $this->showPaymentForm = ! $this->showPaymentForm;
        $this->showReturnForm = false;
        $this->notice = '';
        $this->resetErrorBag();

        // Most receipts clear the balance, so that is what the box starts at.
        $this->payAmount = $this->showPaymentForm ? $this->sale->balanceDue() : '';
    }

    public function setPayMethod(string $method): void
    {
        if (! in_array($method, PaymentMethod::methods(), true)) {
            return;
        }

        if ($method === PaymentMethod::BANK && $this->banks->isEmpty()) {
            return;
        }

        $this->payMethod = $method;
        $this->payBankId = null;
        $this->payReference = '';
        $this->payTendered = '';
        $this->payProof = null;
    }

    public function receivePayment(): void
    {
        $this->resetErrorBag();
        $this->validate(['payProof' => ReceiptProof::validationRule()]);

        $payment = (new PosSalePayments(Tenant::current(), auth()->user()))->receive($this->sale, [
            'method' => $this->payMethod,
            'bank_id' => $this->payBankId ?: null,
            'amount' => $this->payAmount,
            'reference' => $this->payReference,
            'tendered' => $this->payTendered,
            'proof' => $this->payProof,
        ]);

        $this->reset(['showPaymentForm', 'payAmount', 'payMethod', 'payBankId', 'payReference', 'payTendered', 'payProof']);
        $this->refreshSale();

        $this->notice = PosCalculator::money((string) $payment->amount)." received — receipt {$payment->receipt_number}.";
    }

    // ── Take goods back ────────────────────────────────────────────────

    public function toggleReturnForm(): void
    {
        $this->showReturnForm = ! $this->showReturnForm;
        $this->showPaymentForm = false;
        $this->notice = '';
        $this->resetErrorBag();
        $this->returnQty = [];
        $this->refundMode = PaymentMethod::CASH;
        unset($this->returnQuote);
    }

    public function setRefundMode(string $mode): void
    {
        $allowed = [PaymentMethod::CASH, PaymentMethod::BANK, \App\Models\SaleReturn::MODE_CREDIT];

        if (! in_array($mode, $allowed, true)) {
            return;
        }

        if ($mode === PaymentMethod::BANK && $this->banks->isEmpty()) {
            return;
        }

        if ($mode === \App\Models\SaleReturn::MODE_CREDIT && ! $this->sale->customer_id) {
            return;
        }

        $this->refundMode = $mode;
        $this->refundBankId = null;
        $this->refundReference = '';
        $this->refundProof = null;
    }

    /** Typing a quantity is held to what can still come back, line by line. */
    public function updatedReturnQty(): void
    {
        foreach ($this->sale->returnableGroups() as $index => $group) {
            if (! isset($this->returnQty[$index])) {
                continue;
            }

            $this->returnQty[$index] = (string) max(0, min((int) $this->returnQty[$index], $group->returnable));
        }

        unset($this->returnQuote);
    }

    /**
     * The form works in lines as printed (one per product); the sale stores
     * one row per serial or purchase batch. Units go back newest-first, which
     * is the order a shelf is restocked in.
     *
     * @return array<int, int> sale_item_id => units
     */
    private function allocation(): array
    {
        $quantities = [];

        foreach ($this->sale->returnableGroups() as $index => $group) {
            $remaining = max(0, min((int) ($this->returnQty[$index] ?? 0), $group->returnable));

            foreach ($group->rows->reverse() as $row) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, $row->returnableQuantity());

                if ($take > 0) {
                    $quantities[$row->id] = $take;
                    $remaining -= $take;
                }
            }
        }

        return $quantities;
    }

    /** What the units chosen so far are worth and how much of it goes back to the customer. */
    #[Computed]
    public function returnQuote(): ?array
    {
        $quantities = $this->allocation();

        if ($quantities === []) {
            return null;
        }

        try {
            return (new PosReturns(Tenant::current(), auth()->user()))
                ->quote($this->sale, $this->sale->items->keyBy('id'), $quantities);
        } catch (ValidationException) {
            return null;
        }
    }

    public function submitReturn(): void
    {
        $this->resetErrorBag();
        $this->validate(['refundProof' => ReceiptProof::validationRule()]);

        $return = (new PosReturns(Tenant::current(), auth()->user()))->process(
            $this->sale,
            $this->allocation(),
            [
                'mode' => $this->refundMode,
                'bank_id' => $this->refundBankId ?: null,
                'reference' => $this->refundReference,
                'proof' => $this->refundProof,
            ],
            $this->returnNotes,
        );

        $this->reset(['showReturnForm', 'returnQty', 'refundMode', 'refundBankId', 'refundReference', 'refundProof', 'returnNotes']);
        $this->refreshSale();

        $this->notice = "Return {$return->return_number} recorded — ".PosCalculator::money((string) $return->total_value).' of goods back in stock.';
    }
} ?>

@php
    $money = fn ($value) => \App\Support\PosCalculator::money((string) $value);
    $card = 'rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5';
    $input = 'block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm shadow-sm focus:border-walnut-400 focus:ring-walnut-400';
    $button = 'inline-flex items-center gap-2 rounded-lg bg-white/70 border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white';

    $balance = $sale->balanceDue();
    $hasBalance = bccomp($balance, '0', 2) > 0;
    $groups = $sale->returnableGroups();
    $canReturn = $groups->sum('returnable') > 0;
    $quote = $this->returnQuote;
    $refundDue = $quote['overpaid'] ?? '0.00';
@endphp

@slot('header')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $sale->sale_number }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $sale->sold_at->format('d M Y, h:i A') }}@if ($sale->seller) · sold by {{ $sale->seller->name }}@endif</p>
        </div>
        <x-status-badge :status="$sale->paymentState()" />
    </div>
@endslot

<div class="max-w-4xl space-y-6">
    @if (session('status'))
        <div class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($notice !== '')
        <div class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $notice }}
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        <a href="{{ route('tenant.sales.receipt', $sale) }}" target="_blank"
           class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
            <x-tenant-icon name="printer" class="h-4 w-4" /> Print Receipt (80mm)
        </a>
        <a href="{{ route('tenant.sales.invoice', $sale) }}" target="_blank" class="{{ $button }}">
            <x-tenant-icon name="document-text" class="h-4 w-4" /> A4 Invoice
        </a>
        <a href="{{ $sale->whatsappUrl() }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">
            <x-tenant-icon name="phone" class="h-4 w-4" /> Send via WhatsApp
        </a>
        @if ($hasBalance)
            <button type="button" wire:click="togglePaymentForm"
                class="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-400">
                <x-tenant-icon name="banknotes" class="h-4 w-4" /> {{ $showPaymentForm ? 'Cancel' : 'Receive Payment' }}
            </button>
        @endif
        @if ($canReturn)
            <button type="button" wire:click="toggleReturnForm" class="{{ $button }}">
                {{ $showReturnForm ? 'Cancel return' : 'Return Items' }}
            </button>
        @endif
        <a href="{{ route('tenant.pos.register') }}" wire:navigate class="{{ $button }}">
            <x-tenant-icon name="plus" class="h-4 w-4" /> New Sale
        </a>
    </div>

    {{-- Receive a payment ─────────────────────────────────────────────── --}}
    @if ($showPaymentForm && $hasBalance)
        <form wire:submit="receivePayment" class="{{ $card }} p-5 space-y-4" wire:loading.class="opacity-50 pointer-events-none" wire:target="receivePayment">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">Receive Payment</h3>
                <p class="text-sm text-gray-500">Balance due {{ $money($balance) }}@if ($sale->due_date) · due {{ $sale->due_date->format('d M Y') }}@endif</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label value="Amount received" />
                    <input type="number" min="0" step="0.01" wire:model="payAmount" class="mt-1 {{ $input }}">
                    <x-input-error :messages="$errors->get('payment.0.amount')" class="mt-1" />
                    <x-input-error :messages="$errors->get('payment')" class="mt-1" />
                </div>

                <div>
                    <x-input-label value="Method" />
                    <div class="mt-1 flex gap-2">
                        @foreach ([\App\Support\PaymentMethod::CASH => 'Cash', \App\Support\PaymentMethod::BANK => 'Bank'] as $method => $label)
                            <button type="button" wire:click="setPayMethod('{{ $method }}')"
                                @disabled($method === \App\Support\PaymentMethod::BANK && $this->banks->isEmpty())
                                class="flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed
                                       {{ $payMethod === $method
                                            ? ($method === 'cash' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-sky-600 text-white border-sky-600')
                                            : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-gray-400' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($payMethod === 'cash')
                    <div class="sm:col-span-2">
                        <x-input-label value="Cash received (optional — to work out change)" />
                        <input type="number" min="0" step="0.01" wire:model="payTendered" class="mt-1 {{ $input }}">
                        <x-input-error :messages="$errors->get('payment.0.tendered')" class="mt-1" />
                    </div>
                @else
                    <div>
                        <x-input-label value="Bank" />
                        <select wire:model="payBankId" class="mt-1 {{ $input }}">
                            <option value="">Select a bank…</option>
                            @foreach ($this->banks as $bank)
                                <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('payment.0.bank_id')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Transaction / reference no. (optional)" />
                        <x-text-input wire:model="payReference" class="mt-1 block w-full text-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label value="Receipt / proof of transfer (optional)" />
                        <input type="file" wire:model="payProof" accept="image/png,image/jpeg,image/webp,application/pdf"
                            class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-sky-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-sky-700 hover:file:bg-sky-100">
                        <p wire:loading wire:target="payProof" class="mt-1 text-xs text-gray-400">Uploading…</p>
                        <x-input-error :messages="$errors->get('payProof')" class="mt-1" />
                    </div>
                @endif
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="receivePayment"
                class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-50">
                <span wire:loading.remove wire:target="receivePayment">Record Payment</span>
                <span wire:loading wire:target="receivePayment">Recording…</span>
            </button>
        </form>
    @endif

    {{-- Take goods back ───────────────────────────────────────────────── --}}
    @if ($showReturnForm && $canReturn)
        <form wire:submit="submitReturn" class="{{ $card }} p-5 space-y-4" wire:loading.class="opacity-50 pointer-events-none" wire:target="submitReturn">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">Return Items</h3>
                <p class="text-sm text-gray-500">Returned units go back into stock. What each is worth is what the customer paid for it, after discounts.</p>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($groups as $index => $group)
                    <div class="flex items-center justify-between gap-3 py-2.5" wire:key="return-group-{{ $index }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $group->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $group->serial ? 'S/N '.$group->serial.' · ' : '' }}{{ $money($group->unit_price) }} each
                                · {{ $group->returnable }} of {{ $group->sold }} can be returned
                            </p>
                        </div>

                        @if ($group->returnable > 0)
                            <input type="number" min="0" max="{{ $group->returnable }}" placeholder="0"
                                wire:model.live.debounce.400ms="returnQty.{{ $index }}"
                                class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-1 text-right text-sm focus:border-walnut-400 focus:ring-walnut-400">
                        @else
                            <span class="text-xs text-gray-400">Returned</span>
                        @endif
                    </div>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('items')" />

            @if ($quote)
                <dl class="space-y-1.5 rounded-xl bg-gray-50 dark:bg-gray-900/40 p-4 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Value of goods returned</dt><dd class="font-semibold text-gray-900 dark:text-white">{{ $money($quote['value']) }}</dd></div>
                    @if (bccomp($refundDue, '0', 2) > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">To go back to the customer</dt><dd class="font-semibold text-emerald-600">{{ $money($refundDue) }}</dd></div>
                    @else
                        <p class="text-xs text-gray-500">
                            Nothing to pay out — this reduces what the customer still owes{{ $hasBalance ? ' (currently '.$money($balance).')' : '' }}.
                        </p>
                    @endif
                </dl>
            @endif

            @if ($quote && bccomp($refundDue, '0', 2) > 0)
                <div class="space-y-3">
                    <x-input-label :value="'Refund '.$money($refundDue).' as'" />
                    <div class="flex flex-wrap gap-2">
                        @foreach ([
                            \App\Support\PaymentMethod::CASH => 'Cash',
                            \App\Support\PaymentMethod::BANK => 'Bank',
                            \App\Models\SaleReturn::MODE_CREDIT => 'Credit to ledger',
                        ] as $mode => $label)
                            @php
                                $unavailable = ($mode === \App\Support\PaymentMethod::BANK && $this->banks->isEmpty())
                                    || ($mode === \App\Models\SaleReturn::MODE_CREDIT && ! $sale->customer_id);
                            @endphp
                            <button type="button" wire:click="setRefundMode('{{ $mode }}')" @disabled($unavailable)
                                title="{{ $mode === \App\Models\SaleReturn::MODE_CREDIT && ! $sale->customer_id ? 'Walk-in sales have no ledger' : '' }}"
                                class="rounded-lg border px-4 py-2 text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed
                                       {{ $refundMode === $mode
                                            ? 'bg-walnut-600 text-white border-walnut-600'
                                            : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-gray-400' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('refund.mode')" />

                    @if ($refundMode === \App\Models\SaleReturn::MODE_CREDIT)
                        <p class="text-xs text-gray-500">
                            Stays on {{ $sale->customerName() }}'s ledger as a credit in their favour — nothing is paid out now.
                        </p>
                    @endif

                    @if ($refundMode === 'bank')
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <select wire:model="refundBankId" class="{{ $input }}">
                                    <option value="">Select a bank…</option>
                                    @foreach ($this->banks as $bank)
                                        <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('refund.bank_id')" class="mt-1" />
                            </div>
                            <x-text-input wire:model="refundReference" placeholder="Transaction / reference no. (optional)" class="block w-full text-sm" />
                            <div class="sm:col-span-2">
                                <input type="file" wire:model="refundProof" accept="image/png,image/jpeg,image/webp,application/pdf"
                                    class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-sky-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-sky-700 hover:file:bg-sky-100">
                                <x-input-error :messages="$errors->get('refundProof')" class="mt-1" />
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <x-text-input wire:model="returnNotes" placeholder="Reason / note (optional)" class="block w-full text-sm" />

            <button type="submit" wire:loading.attr="disabled" wire:target="submitReturn" @disabled(! $quote)
                class="inline-flex items-center justify-center rounded-lg bg-rose-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-rose-500 disabled:opacity-50 disabled:cursor-not-allowed">
                <span wire:loading.remove wire:target="submitReturn">Confirm Return</span>
                <span wire:loading wire:target="submitReturn">Processing…</span>
            </button>
        </form>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div class="{{ $card }} p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Customer</h3>
            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $sale->customerName() }}</p>
            @if ($sale->customerPhone())
                <p class="text-sm text-gray-500">{{ $sale->customerPhone() }}</p>
            @endif
            @if ($sale->customer)
                <a href="{{ route('tenant.customers.ledger', $sale->customer) }}" wire:navigate class="mt-2 inline-block text-sm text-walnut-400 hover:text-walnut-200">View ledger</a>
            @else
                <p class="mt-1 text-xs text-gray-400">Walk-in sale — not linked to a customer record.</p>
            @endif
        </div>

        <div class="{{ $card }} p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Totals</h3>
            <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd>{{ $money($sale->subtotal) }}</dd></div>
                @if (bccomp((string) $sale->line_discount_total, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Item discounts</dt><dd class="text-rose-600">−{{ $money($sale->line_discount_total) }}</dd></div>
                @endif
                @if (bccomp((string) $sale->bill_discount_amount, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Bill discount</dt><dd class="text-rose-600">−{{ $money($sale->bill_discount_amount) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-gray-100 dark:border-gray-700 pt-1.5 font-semibold"><dt>Total</dt><dd>{{ $money($sale->total) }}</dd></div>
                @if (bccomp((string) $sale->returned_total, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Returned</dt><dd class="text-rose-600">−{{ $money($sale->returned_total) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-gray-500">Paid</dt><dd class="text-emerald-600">{{ $money($sale->amount_paid) }}</dd></div>
                @if (bccomp((string) $sale->refunded_total, '0', 2) > 0)
                    <div class="flex justify-between"><dt class="text-gray-500">Refunded</dt><dd>−{{ $money($sale->refunded_total) }}</dd></div>
                @endif
                @if ($hasBalance)
                    <div class="flex justify-between border-t border-gray-100 dark:border-gray-700 pt-1.5 font-semibold {{ $sale->isOverdue() ? 'text-rose-600' : 'text-amber-600' }}">
                        <dt>Balance due</dt><dd>{{ $money($balance) }}</dd>
                    </div>
                    @if ($sale->due_date)
                        <p class="text-xs {{ $sale->isOverdue() ? 'text-rose-600' : 'text-gray-500' }}">
                            Due {{ $sale->due_date->format('d M Y') }}{{ $sale->isOverdue() ? ' — overdue by '.$sale->due_date->diffInDays(today()).' '.\Illuminate\Support\Str::plural('day', $sale->due_date->diffInDays(today())) : '' }}
                        </p>
                    @endif
                @elseif (bccomp($sale->overpaid(), '0', 2) > 0)
                    <div class="flex justify-between border-t border-gray-100 dark:border-gray-700 pt-1.5 font-semibold text-sky-600">
                        <dt>Credit with the shop</dt><dd>{{ $money($sale->overpaid()) }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>

    <div class="{{ $card }} overflow-hidden">
        <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Items</h3>
        <div class="overflow-x-auto mt-4">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Item</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Serial / IMEI</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Qty</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Price</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Discount</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($sale->displayLines() as $line)
                        <tr class="{{ $line->returned >= $line->quantity ? 'opacity-60' : '' }}">
                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">
                                {{ $line->name }}
                                @if ($line->returned > 0)
                                    <span class="ml-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                                        {{ $line->returned >= $line->quantity ? 'Returned' : $line->returned.' returned' }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-500">{{ $line->serial ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $line->quantity }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $money($line->unit_price) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ bccomp($line->discount, '0', 2) > 0 ? $money($line->discount) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ $money($line->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="{{ $card }} overflow-hidden">
        <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Payments</h3>
        <div class="overflow-x-auto mt-4">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Date</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Receipt</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Method</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Reference</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Amount</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Change</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($sale->payments as $payment)
                        <tr wire:key="payment-{{ $payment->id }}">
                            <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap">{{ $payment->paid_at->format('d M Y, h:i A') }}</td>
                            <td class="px-4 py-2.5">
                                <a href="{{ route('tenant.sales.payment-receipt', [$sale, $payment]) }}" target="_blank" class="font-mono text-xs text-walnut-400 hover:text-walnut-200">{{ $payment->receipt_number }}</a>
                            </td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                {{ \App\Support\PaymentMethod::label($payment->payment_mode, $payment->bank) }}
                                <x-receipt-proof-link :proof="$payment->receiptProof()" :missing="$payment->missingReceiptProof()" class="ml-1" />
                            </td>
                            <td class="px-4 py-2.5 text-gray-500">{{ $payment->reference_number ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ $money($payment->amount) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ $payment->changeGiven() ? $money($payment->changeGiven()) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">Nothing paid yet — the whole amount is on credit.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($sale->returns->isNotEmpty())
        <div class="{{ $card }} overflow-hidden">
            <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Returns</h3>
            <div class="overflow-x-auto mt-4">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Return</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Date</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Items</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Value</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Money back</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($sale->returns as $return)
                            <tr wire:key="return-{{ $return->id }}">
                                <td class="px-4 py-2.5 font-mono text-xs text-gray-700 dark:text-gray-300">
                                    {{ $return->return_number }}
                                    <span class="block font-sans text-gray-400">{{ $return->returner?->name }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap">{{ $return->returned_at->format('d M Y, h:i A') }}</td>
                                <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                    @foreach ($return->items as $returnItem)
                                        <p>{{ $returnItem->quantity }} × {{ $returnItem->saleItem?->product_name }}@if ($returnItem->saleItem?->serial_number) <span class="font-mono text-xs text-gray-400">({{ $returnItem->saleItem->serial_number }})</span>@endif</p>
                                    @endforeach
                                    @if ($return->notes)
                                        <p class="text-xs text-gray-400">{{ $return->notes }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ $money($return->total_value) }}</td>
                                <td class="px-4 py-2.5 text-gray-500">
                                    @if (bccomp((string) $return->refund_amount, '0', 2) > 0)
                                        {{ $money($return->refund_amount) }} · {{ $return->refundLabel() }}
                                        <x-receipt-proof-link :proof="$return->receiptProof()" :missing="$return->missingReceiptProof()" class="ml-1" />
                                    @elseif (bccomp((string) $return->credit_amount, '0', 2) > 0)
                                        {{ $money($return->credit_amount) }} · left on ledger
                                    @else
                                        Reduced the balance owed
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($sale->notes)
        <div class="{{ $card }} p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Note</h3>
            <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{{ $sale->notes }}</p>
        </div>
    @endif
</div>
