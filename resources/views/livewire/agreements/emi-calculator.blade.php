<?php

use App\Support\EmiCalculator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public string $productPrice = '50000';

    public string $downPaymentType = 'percent'; // percent | fixed

    public string $downPaymentValue = '20';

    public string $interestRate = '12';

    public int $durationMonths = 12;

    public string $processingFee = '0';

    public string $startDate = '';

    /** @var array<int, int> */
    public array $durations = [3, 6, 9, 12, 18, 24];

    public function mount(): void
    {
        $shop = \App\Support\Tenant::current();
        $this->interestRate = (string) ($shop?->default_interest_rate ?? '12');
        $this->processingFee = (string) ($shop?->default_processing_fee ?? '0');
        $this->startDate = now()->toDateString();
    }

    #[Computed]
    public function downPaymentAmount(): string
    {
        $price = $this->numeric($this->productPrice);

        if ($this->downPaymentType === 'percent') {
            $percent = $this->numeric($this->downPaymentValue);

            return bcdiv(bcmul($price, $percent, 4), '100', 2);
        }

        return $this->numeric($this->downPaymentValue);
    }

    #[Computed]
    public function calculator(): EmiCalculator
    {
        return new EmiCalculator(
            productPrice: $this->numeric($this->productPrice),
            downPayment: $this->downPaymentAmount,
            processingFee: $this->numeric($this->processingFee),
            interestRate: $this->numeric($this->interestRate),
            durationMonths: $this->durationMonths,
        );
    }

    #[Computed]
    public function schedule(): array
    {
        if (bccomp($this->calculator->financedAmount(), '0', 2) <= 0) {
            return [];
        }

        return $this->calculator->schedule($this->startDate ?: now()->toDateString());
    }

    private function numeric(?string $value): string
    {
        return $value === null || $value === '' || ! is_numeric($value) ? '0' : $value;
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">EMI Calculator</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Preview installment terms before starting an agreement</p>
    @endslot

<div>

    <div class="space-y-6" x-data="{ showSchedule: false }">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-5">
            <h3 class="font-semibold text-gray-900 dark:text-white">EMI Calculator</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Product Price</label>
                    <input type="number" step="0.01" min="0" wire:model.live.debounce.300ms="productPrice"
                        class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Down Payment</label>
                    <div class="mt-1 flex rounded-lg shadow-sm">
                        <input type="number" step="0.01" min="0" wire:model.live.debounce.300ms="downPaymentValue"
                            class="block w-full rounded-l-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-walnut-400 focus:ring-walnut-400">
                        <select wire:model.live="downPaymentType" class="rounded-r-lg border-l-0 border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <option value="percent">%</option>
                            <option value="fixed">Rs.</option>
                        </select>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">≈ Rs. {{ number_format((float) $this->downPaymentAmount, 2) }}</p>
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Markup / Interest Rate (annual %)</label>
                    <input type="number" step="0.01" min="0" wire:model.live.debounce.300ms="interestRate"
                        class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Processing Fee</label>
                    <input type="number" step="0.01" min="0" wire:model.live.debounce.300ms="processingFee"
                        class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
            </div>

            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Duration</label>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($durations as $months)
                        <button type="button" wire:click="$set('durationMonths', {{ $months }})"
                            class="rounded-full px-4 py-1.5 text-sm font-medium transition
                                   {{ $durationMonths === $months
                                        ? 'bg-walnut-600 text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $months }} mo
                        </button>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Start Date</label>
                <input type="date" wire:model.live="startDate"
                    class="mt-1 block w-full sm:w-56 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
            </div>
        </div>

        <div class="rounded-2xl border border-walnut-200/60 dark:border-walnut-900/60 bg-walnut-50/70 dark:bg-walnut-900/40 backdrop-blur-xl shadow-lg shadow-walnut-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-walnut-900 dark:text-walnut-200">Summary</h3>

            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-walnut-600/70 dark:text-walnut-200/70">Financed Amount</dt>
                    <dd class="font-medium text-walnut-900 dark:text-walnut-200">Rs. {{ number_format((float) $this->calculator->financedAmount(), 2) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-walnut-600/70 dark:text-walnut-200/70">Total Interest</dt>
                    <dd class="font-medium text-walnut-900 dark:text-walnut-200">Rs. {{ number_format((float) $this->calculator->totalInterest(), 2) }}</dd>
                </div>
                <div class="flex justify-between border-t border-walnut-200 dark:border-walnut-900 pt-3">
                    <dt class="text-walnut-600/70 dark:text-walnut-200/70">Total Payable</dt>
                    <dd class="font-semibold text-walnut-900 dark:text-walnut-200">Rs. {{ number_format((float) $this->calculator->totalPayable(), 2) }}</dd>
                </div>
            </dl>

            <div class="rounded-xl bg-white/70 dark:bg-gray-900/50 p-4 text-center">
                <p class="text-xs text-walnut-600/70 dark:text-walnut-200/70">Monthly Installment</p>
                <p class="text-3xl font-bold text-walnut-600 dark:text-walnut-200">Rs. {{ number_format((float) $this->calculator->monthlyInstallment(), 2) }}</p>
                <p class="text-xs text-walnut-400/70">for {{ $durationMonths }} months</p>
            </div>

            <button type="button" @click="showSchedule = ! showSchedule"
                class="w-full rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400 transition">
                <span x-text="showSchedule ? 'Hide Schedule' : 'View Schedule'"></span>
            </button>
        </div>
    </div>

    <div x-show="showSchedule" x-cloak class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">#</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Due Date</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Opening</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Principal</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Interest</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Installment</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Closing</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->schedule as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-gray-500">{{ $row['installment_number'] }}</td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->format('d M Y') }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ number_format((float) $row['opening_balance'], 2) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ number_format((float) $row['principal_component'], 2) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ number_format((float) $row['interest_component'], 2) }}</td>
                            <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">{{ number_format((float) $row['total_due'], 2) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-500">{{ number_format((float) $row['closing_balance'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">Enter a product price to see the schedule.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    </div>
</div>
