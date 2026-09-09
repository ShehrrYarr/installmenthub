<?php

use App\Models\Expense;
use App\Support\PaymentMethod;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public ?Expense $expense = null;

    #[Validate('required|string|max:100')]
    public string $category = '';

    #[Validate('required|integer|min:1')]
    public string $amount = '';

    #[Validate('required|date|before_or_equal:today')]
    public string $expense_date = '';

    public string $description = '';

    public string $paymentMethod = PaymentMethod::CASH;

    public ?int $bankId = null;

    public function mount(?Expense $expense = null): void
    {
        // Anyone with manage-expenses can add a new expense; editing an
        // existing one is Shop Admin only.
        abort_unless(auth()->user()->can('manage-expenses'), 403);

        if ($expense?->exists) {
            abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

            $this->expense = $expense;
            $this->category = $expense->category;
            $this->amount = (string) $expense->amount;
            $this->expense_date = $expense->expense_date->toDateString();
            $this->description = $expense->description ?? '';
            [$this->paymentMethod, $this->bankId] = PaymentMethod::forForm($expense->payment_mode, $expense->bank_id);
        } else {
            $this->expense_date = now()->toDateString();
        }
    }

    public function save(): void
    {
        $this->validate();
        $this->validate([
            'paymentMethod' => PaymentMethod::methodRule(),
            'bankId' => PaymentMethod::bankRule('paymentMethod'),
        ], PaymentMethod::bankMessages('bankId'));

        [$paymentMode, $bankId] = PaymentMethod::toStorage($this->paymentMethod, $this->bankId);

        $data = [
            'category' => $this->category,
            'amount' => $this->amount,
            'expense_date' => $this->expense_date,
            'description' => $this->description ?: null,
            'payment_mode' => $paymentMode,
            'bank_id' => $bankId,
        ];

        if ($this->expense) {
            $this->expense->update($data);
        } else {
            Expense::create([...$data, 'created_by' => auth()->id()]);
        }

        session()->flash('status', $this->expense ? 'Expense updated.' : 'Expense added.');

        $this->redirect(
            \Illuminate\Support\Facades\Route::has('tenant.expenses.index') ? route('tenant.expenses.index') : '/',
            navigate: true
        );
    }
} ?>

@slot('header')
    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $expense?->exists ? 'Edit Expense' : 'New Expense' }}</h1>
@endslot

<div>
    <form wire:submit="save" class="max-w-2xl space-y-6" wire:loading.class="opacity-50 pointer-events-none" wire:target="save">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label value="Category" />
                    <select wire:model="category" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <option value="">Select category…</option>
                        @foreach (\App\Models\Expense::CATEGORIES as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('category')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="Amount" />
                    <x-text-input type="number" step="1" wire:model="amount" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="Date" />
                    <x-text-input type="date" wire:model="expense_date" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('expense_date')" class="mt-1" />
                </div>
                <x-payment-method-select method-model="paymentMethod" bank-model="bankId" label="Payment Mode" class="sm:col-span-2" />
                <div class="sm:col-span-2">
                    <x-input-label value="Description (optional)" />
                    <x-text-input wire:model="description" placeholder="e.g. September shop rent" class="mt-1 block w-full" />
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-walnut-400 disabled:opacity-50 disabled:cursor-not-allowed">
                <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span wire:loading.remove wire:target="save">Save Expense</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.expenses.index') ? route('tenant.expenses.index') : '/' }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
    </form>
</div>
