<?php

use App\Models\Expense;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithPagination;

    public string $category = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage-expenses'), 403);
    }

    public function with(): array
    {
        $query = Expense::query()
            ->when($this->category !== 'all', fn ($q) => $q->where('category', $this->category));

        $expenses = (clone $query)->with(['creator', 'bank'])->latest('expense_date')->latest('id')->paginate(15);
        $total = (string) (clone $query)->sum('amount');

        return ['expenses' => $expenses, 'total' => $total];
    }
} ?>

@slot('header')
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Expenses</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">Shop operating expenses</p>
            </div>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.expenses.create') ? route('tenant.expenses.create') : '#' }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                <x-tenant-icon name="plus" class="h-4 w-4" /> New Expense
            </a>
        </div>
    @endslot

<div>

    <div class="space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
            <select wire:model.live="category" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm">
                <option value="all">All categories</option>
                @foreach (\App\Models\Expense::CATEGORIES as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <p class="sm:ml-auto text-sm text-gray-500 dark:text-gray-400">Total: <span class="font-semibold text-gray-900 dark:text-white">Rs. {{ number_format((float) $total, 0) }}</span></p>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Date</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Category</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Description</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Payment Mode</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Added By</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Amount</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($expenses as $expense)
                            <tr>
                                <td class="px-4 py-2.5 text-gray-500">{{ $expense->expense_date->format('d M Y') }}</td>
                                <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ $expense->category }}</td>
                                <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $expense->description ?: '—' }}</td>
                                <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ \App\Support\PaymentMethod::label($expense->payment_mode, $expense->bank) }}</td>
                                <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $expense->creator?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">Rs. {{ number_format((float) $expense->amount, 0) }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    @if (auth()->user()->hasRole('Shop Admin'))
                                        <a href="{{ route('tenant.expenses.edit', $expense) }}" wire:navigate class="text-walnut-400 hover:text-walnut-400 font-medium">Edit</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No expenses recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $expenses->links() }}
    </div>
</div>
