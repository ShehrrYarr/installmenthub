<?php

use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public bool $invoice_show_letterhead = true;

    public bool $invoice_show_agreement_info = true;

    public bool $invoice_show_customer_info = true;

    public bool $invoice_show_product_items = true;

    public bool $invoice_show_financial_summary = true;

    public bool $invoice_show_guarantors = true;

    public bool $invoice_show_salesman = true;

    public bool $invoice_show_emi_schedule = true;

    public bool $invoice_show_terms = true;

    public bool $invoice_show_notes = true;

    public string $invoice_terms_text = '';

    /** @var array<int, array{key: string, icon: string, label: string, description: string}> */
    public array $sections = [
        ['key' => 'invoice_show_letterhead', 'icon' => 'building-storefront', 'label' => 'Shop Letterhead', 'description' => 'Shop name, address, phone, and email at the top of the invoice.'],
        ['key' => 'invoice_show_agreement_info', 'icon' => 'document-text', 'label' => 'Agreement Info', 'description' => 'Agreement number, status, start date, first due date, and who approved it.'],
        ['key' => 'invoice_show_customer_info', 'icon' => 'users', 'label' => 'Customer Info', 'description' => "The customer's name, CNIC, phone, and address."],
        ['key' => 'invoice_show_product_items', 'icon' => 'cube', 'label' => 'Product Items', 'description' => 'Each product on the agreement, its serial number, and its price.'],
        ['key' => 'invoice_show_financial_summary', 'icon' => 'banknotes', 'label' => 'Financial Summary', 'description' => 'Price, down payment, processing fee, interest, financed amount, and monthly installment.'],
        ['key' => 'invoice_show_guarantors', 'icon' => 'shield-check', 'label' => 'Guarantors', 'description' => "Both guarantors' names, relation, CNIC, and phone."],
        ['key' => 'invoice_show_salesman', 'icon' => 'sparkles', 'label' => 'Salesman', 'description' => 'The staff member who created the agreement.'],
        ['key' => 'invoice_show_emi_schedule', 'icon' => 'calendar-days', 'label' => 'EMI Schedule', 'description' => 'The full month-by-month installment schedule table.'],
        ['key' => 'invoice_show_notes', 'icon' => 'document-text', 'label' => 'Agreement Notes', 'description' => 'Any notes recorded on the agreement itself, if present.'],
        ['key' => 'invoice_show_terms', 'icon' => 'exclamation-triangle', 'label' => 'Terms & Conditions', 'description' => 'The text below, printed at the bottom of the invoice.'],
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $shop = Tenant::current();

        foreach ($this->sections as $section) {
            $key = $section['key'];
            $this->{$key} = (bool) ($shop?->{$key} ?? true);
        }

        $this->invoice_terms_text = (string) ($shop?->invoice_terms_text ?? '');
    }

    public function save(): void
    {
        $shop = Tenant::current();
        abort_unless($shop, 403);

        $data = ['invoice_terms_text' => $this->invoice_terms_text];

        foreach ($this->sections as $section) {
            $key = $section['key'];
            $data[$key] = $this->{$key};
        }

        $shop->update($data);

        session()->flash('status', 'Invoice settings updated — applies the next time an invoice is printed.');
    }
} ?>

@slot('header')
    <h1 class="text-xl font-semibold" style="color: var(--theme-bg-text)">Settings</h1>
    <p class="text-sm opacity-70" style="color: var(--theme-bg-text)">What appears on a printed agreement invoice</p>
@endslot

<div>
    {{-- Settings sub-nav — scrolls horizontally with hidden scrollbar + edge
         fades (matching the mobile bottom nav) whenever the tabs don't fit. --}}
    <div class="relative mb-6">
        <div class="flex gap-1 overflow-x-auto no-scrollbar border-b border-black/10">
            <a href="{{ route('tenant.settings.appearance') }}" wire:navigate
               class="flex shrink-0 items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <x-tenant-icon name="swatch" class="h-4 w-4" />
                Appearance
            </a>
            <a href="{{ route('tenant.settings.staff') }}" wire:navigate
               class="flex shrink-0 items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <x-tenant-icon name="users" class="h-4 w-4" />
                Staff
            </a>
            <a href="{{ route('tenant.settings.banks') }}" wire:navigate
               class="flex shrink-0 items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <x-tenant-icon name="building-storefront" class="h-4 w-4" />
                Banks
            </a>
            <a href="{{ route('tenant.settings.emi') }}" wire:navigate
               class="flex shrink-0 items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <x-tenant-icon name="calculator" class="h-4 w-4" />
                EMI Settings
            </a>
            <div class="flex shrink-0 items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2"
                 style="color: var(--theme-accent); border-color: var(--theme-accent)">
                <x-tenant-icon name="document-text" class="h-4 w-4" />
                Invoice
            </div>
        </div>
        <div class="pointer-events-none absolute inset-y-0 left-0 w-6 bg-gradient-to-r from-[var(--theme-bg)] to-transparent"></div>
        <div class="pointer-events-none absolute inset-y-0 right-0 w-6 bg-gradient-to-l from-[var(--theme-bg)] to-transparent"></div>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Invoice Sections</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Choose what appears when a Shop Admin or Manager prints an agreement's invoice.</p>

            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($sections as $section)
                    <div class="flex items-start justify-between gap-4 py-3.5 first:pt-0 last:pb-0">
                        <div class="flex items-start gap-3">
                            <x-tenant-icon :name="$section['icon']" class="h-5 w-5 mt-0.5 shrink-0 text-gray-400" />
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ $section['label'] }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $section['description'] }}</p>
                            </div>
                        </div>

                        <button type="button" wire:click="$toggle('{{ $section['key'] }}')" role="switch"
                            aria-checked="{{ $this->{$section['key']} ? 'true' : 'false' }}"
                            class="relative mt-1 inline-flex h-7 w-12 shrink-0 cursor-pointer items-center rounded-full p-0.5 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-walnut-400 focus:ring-offset-2
                                   {{ $this->{$section['key']}
                                        ? 'bg-walnut-600 ring-1 ring-inset ring-walnut-600'
                                        : 'bg-gray-200 ring-1 ring-inset ring-gray-400 dark:bg-gray-700 dark:ring-gray-500' }}">
                            <span class="sr-only">{{ $section['label'] }}</span>
                            <span class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-1 ring-black/10 transition-transform duration-200
                                         {{ $this->{$section['key']} ? 'translate-x-5' : 'translate-x-0' }}"></span>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 {{ $invoice_show_terms ? '' : 'opacity-50' }}">
            <x-input-label for="invoice_terms_text" value="Terms & Conditions Text" />
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Printed at the bottom of the invoice when the toggle above is on.</p>
            <textarea id="invoice_terms_text" wire:model="invoice_terms_text" rows="5" @disabled(! $invoice_show_terms)
                class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400 disabled:cursor-not-allowed"
                placeholder="e.g. Ownership of the product remains with the shop until the final installment is paid in full..."></textarea>
            <x-input-error :messages="$errors->get('invoice_terms_text')" class="mt-1" />
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="save"
            class="inline-flex items-center justify-center rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-walnut-400 disabled:opacity-50">
            <span wire:loading.remove wire:target="save">Save</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </form>
</div>
