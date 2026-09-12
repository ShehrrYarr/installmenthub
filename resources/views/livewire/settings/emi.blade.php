<?php

use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public string $emi_price_basis;

    public string $emi_first_installment;

    // Moved here from the Super Admin shop form — every shop sets the rate it
    // lends at, the fee it charges, and how it penalises a late instalment.
    #[Validate('required|numeric|min:0|max:100')]
    public string $default_interest_rate = '0';

    #[Validate('required|numeric|min:0')]
    public string $default_processing_fee = '0';

    public bool $penalties_enabled = true;

    #[Validate('required|in:daily,fixed')]
    public string $penalty_type = 'daily';

    #[Validate('required|numeric|min:0')]
    public string $penalty_rate = '0';

    // grace_period_days is an unsigned tinyint.
    #[Validate('required|integer|min:0|max:255')]
    public int $grace_period_days = 0;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $shop = Tenant::current();

        $this->emi_price_basis = $shop?->emi_price_basis ?? 'selling';
        $this->emi_first_installment = $shop?->emi_first_installment ?? 'same_month';

        // Decimal-cast columns hand back "12.00"; the inputs are numeric, so
        // the trailing zeros are only noise in the box.
        $this->default_interest_rate = $this->trimDecimal($shop?->default_interest_rate);
        $this->default_processing_fee = $this->trimDecimal($shop?->default_processing_fee);
        $this->penalties_enabled = (bool) ($shop?->penalties_enabled ?? true);
        $this->penalty_type = $shop?->penalty_type ?? 'daily';
        $this->penalty_rate = $this->trimDecimal($shop?->penalty_rate);
        $this->grace_period_days = (int) ($shop?->grace_period_days ?? 0);
    }

    private function trimDecimal(string|float|null $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
    }

    public function saveDefaults(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $validated = $this->validate();

        $shop = Tenant::current();
        abort_unless($shop, 403);

        $shop->update([...$validated, 'penalties_enabled' => $this->penalties_enabled]);

        session()->flash('status', 'EMI and penalty defaults updated — applies to agreements created from now on.');
    }

    public function setBasis(string $basis): void
    {
        abort_unless(in_array($basis, ['cost', 'selling'], true), 404);

        $shop = Tenant::current();
        abort_unless($shop, 403);

        $shop->update(['emi_price_basis' => $basis]);
        $this->emi_price_basis = $basis;

        session()->flash('status', 'EMI price basis updated — applies to agreements created from now on.');
    }

    public function setFirstInstallment(string $timing): void
    {
        abort_unless(in_array($timing, ['same_month', 'next_month'], true), 404);

        $shop = Tenant::current();
        abort_unless($shop, 403);

        $shop->update(['emi_first_installment' => $timing]);
        $this->emi_first_installment = $timing;

        session()->flash('status', 'First installment timing updated — applies to agreements created from now on.');
    }
} ?>

@slot('header')
    <h1 class="text-xl font-semibold" style="color: var(--theme-bg-text)">Settings</h1>
    <p class="text-sm opacity-70" style="color: var(--theme-bg-text)">How agreements are priced, when instalments fall due, and what a late one costs</p>
@endslot

<div>
    {{-- Settings sub-nav --}}
    <div class="flex gap-1 border-b border-black/10 mb-6">
        <a href="{{ route('tenant.settings.appearance') }}" wire:navigate
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
            <x-tenant-icon name="swatch" class="h-4 w-4" />
            Appearance
        </a>
        <a href="{{ route('tenant.settings.staff') }}" wire:navigate
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
            <x-tenant-icon name="users" class="h-4 w-4" />
            Staff
        </a>
        <a href="{{ route('tenant.settings.banks') }}" wire:navigate
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
            <x-tenant-icon name="building-storefront" class="h-4 w-4" />
            Banks
        </a>
        <div class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2"
             style="color: var(--theme-accent); border-color: var(--theme-accent)">
            <x-tenant-icon name="calculator" class="h-4 w-4" />
            EMI Settings
        </div>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">EMI Price Basis</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">When creating an agreement, which price should "Product Price" start from once a product is selected? Staff can still adjust it by hand afterward.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <button type="button" wire:click="setBasis('selling')"
                class="text-left rounded-2xl border-2 p-4 transition {{ $emi_price_basis === 'selling' ? 'border-walnut-400 shadow-md' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300' }}">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900 dark:text-white">Selling Price</span>
                    @if ($emi_price_basis === 'selling')
                        <span class="inline-flex items-center gap-1 rounded-full bg-walnut-600 px-2 py-0.5 text-xs font-medium text-white">
                            <x-tenant-icon name="check-circle" class="h-3.5 w-3.5" /> Active
                        </span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">The product's cash/retail price — what a walk-in customer would pay outright. Most common choice.</p>
            </button>

            <button type="button" wire:click="setBasis('cost')"
                class="text-left rounded-2xl border-2 p-4 transition {{ $emi_price_basis === 'cost' ? 'border-walnut-400 shadow-md' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300' }}">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900 dark:text-white">Cost Price</span>
                    @if ($emi_price_basis === 'cost')
                        <span class="inline-flex items-center gap-1 rounded-full bg-walnut-600 px-2 py-0.5 text-xs font-medium text-white">
                            <x-tenant-icon name="check-circle" class="h-3.5 w-3.5" /> Active
                        </span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">What the shop paid for the product. Note: this price will then be visible to whoever creates the agreement, including Salesman.</p>
            </button>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-1">First Installment</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
            When does installment #1 fall due, counting from the agreement's start date? The whole schedule shifts with it — the number of installments never changes.
            Staff can still set a different start date on an individual agreement.
        </p>

        @php
            $sameMonthExample = now()->format('d M');
            $nextMonthExample = now()->addMonthNoOverflow()->format('d M');
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <button type="button" wire:click="setFirstInstallment('same_month')"
                class="text-left rounded-2xl border-2 p-4 transition {{ $emi_first_installment === 'same_month' ? 'border-walnut-400 shadow-md' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300' }}">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900 dark:text-white">Same Month</span>
                    @if ($emi_first_installment === 'same_month')
                        <span class="inline-flex items-center gap-1 rounded-full bg-walnut-600 px-2 py-0.5 text-xs font-medium text-white">
                            <x-tenant-icon name="check-circle" class="h-3.5 w-3.5" /> Active
                        </span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Installment #1 is due on the start date itself. An agreement signed today would collect on <span class="font-medium text-gray-700 dark:text-gray-300">{{ $sameMonthExample }}</span>.
                </p>
            </button>

            <button type="button" wire:click="setFirstInstallment('next_month')"
                class="text-left rounded-2xl border-2 p-4 transition {{ $emi_first_installment === 'next_month' ? 'border-walnut-400 shadow-md' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300' }}">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-gray-900 dark:text-white">Next Month</span>
                    @if ($emi_first_installment === 'next_month')
                        <span class="inline-flex items-center gap-1 rounded-full bg-walnut-600 px-2 py-0.5 text-xs font-medium text-white">
                            <x-tenant-icon name="check-circle" class="h-3.5 w-3.5" /> Active
                        </span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Installment #1 is due one month after the start date, same day. An agreement signed today would collect on <span class="font-medium text-gray-700 dark:text-gray-300">{{ $nextMonthExample }}</span>.
                </p>
            </button>
        </div>
    </div>

    <form wire:submit="saveDefaults" class="mt-6 space-y-6">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-1">EMI Defaults</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">What a new agreement starts with. Staff can still change either figure on an individual agreement.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="default_interest_rate" value="Default Interest Rate (annual %)" />
                    <x-text-input id="default_interest_rate" type="number" step="0.01" min="0" max="100" wire:model="default_interest_rate" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('default_interest_rate')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="default_processing_fee" value="Default Processing Fee (Rs.)" />
                    <x-text-input id="default_processing_fee" type="number" step="1" min="0" wire:model="default_processing_fee" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('default_processing_fee')" class="mt-1" />
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Late Payment Penalty</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Charged by the nightly overdue sweep once an instalment is past due by more than the grace period.
                    </p>
                </div>

                {{-- The off state needs its own outline: a pale track on a white
                     card reads as nothing at all, and then the only clue left is
                     which side the knob sits on. --}}
                <button type="button" wire:click="$toggle('penalties_enabled')" role="switch"
                    aria-checked="{{ $penalties_enabled ? 'true' : 'false' }}"
                    class="relative mt-1 inline-flex h-7 w-12 shrink-0 cursor-pointer items-center rounded-full p-0.5 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-walnut-400 focus:ring-offset-2
                           {{ $penalties_enabled
                                ? 'bg-walnut-600 ring-1 ring-inset ring-walnut-600'
                                : 'bg-gray-200 ring-1 ring-inset ring-gray-400 dark:bg-gray-700 dark:ring-gray-500' }}">
                    <span class="sr-only">Charge late payment penalties</span>
                    <span class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-1 ring-black/10 transition-transform duration-200
                                 {{ $penalties_enabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
                </button>
            </div>

            <p class="mt-3 text-sm {{ $penalties_enabled ? 'text-emerald-700 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400' }}">
                @if ($penalties_enabled)
                    Penalties are <span class="font-medium">on</span> — overdue instalments accrue a charge.
                @else
                    Penalties are <span class="font-medium">off</span>. Instalments are still flagged overdue, from the day after they were due, but nothing is charged. Penalties already billed stay on the customer's account.
                @endif
            </p>

            <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-4 {{ $penalties_enabled ? '' : 'opacity-50' }}">
                <div>
                    <x-input-label for="penalty_type" value="Penalty Type" />
                    <select id="penalty_type" wire:model.live="penalty_type" @disabled(! $penalties_enabled)
                        class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400 disabled:cursor-not-allowed">
                        <option value="daily">Daily — per day late</option>
                        <option value="fixed">Fixed — once per instalment</option>
                    </select>
                    <x-input-error :messages="$errors->get('penalty_type')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="penalty_rate" value="Penalty Rate (Rs.)" />
                    <x-text-input id="penalty_rate" type="number" step="1" min="0" wire:model="penalty_rate" :disabled="! $penalties_enabled" class="mt-1 block w-full disabled:cursor-not-allowed" />
                    <p class="mt-1 text-xs text-gray-400">{{ $penalty_type === 'daily' ? 'Charged for each day past the grace period.' : 'Charged once on an overdue instalment.' }}</p>
                    <x-input-error :messages="$errors->get('penalty_rate')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="grace_period_days" value="Grace Period (days)" />
                    <x-text-input id="grace_period_days" type="number" step="1" min="0" max="255" wire:model="grace_period_days" :disabled="! $penalties_enabled" class="mt-1 block w-full disabled:cursor-not-allowed" />
                    <p class="mt-1 text-xs text-gray-400">Days after the due date before a penalty applies.</p>
                    <x-input-error :messages="$errors->get('grace_period_days')" class="mt-1" />
                </div>
            </div>
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="saveDefaults"
            class="inline-flex items-center justify-center rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-walnut-400 disabled:opacity-50">
            <span wire:loading.remove wire:target="saveDefaults">Save Defaults</span>
            <span wire:loading wire:target="saveDefaults">Saving…</span>
        </button>
    </form>
</div>
