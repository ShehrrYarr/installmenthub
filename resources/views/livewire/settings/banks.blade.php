<?php

use App\Models\Bank;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:255')]
    public string $account_title = '';

    #[Validate('nullable|string|max:100')]
    public string $account_number = '';

    #[Validate('nullable|string|max:255')]
    public string $branch = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);
    }

    #[Computed]
    public function banks()
    {
        return Bank::orderBy('name')->get();
    }

    public function toggleForm(): void
    {
        $this->showForm = ! $this->showForm;
        $this->editingId = null;
        $this->resetFields();
    }

    public function startEdit(int $bankId): void
    {
        $bank = Bank::findOrFail($bankId);

        $this->editingId = $bank->id;
        $this->name = $bank->name;
        $this->account_title = (string) $bank->account_title;
        $this->account_number = (string) $bank->account_number;
        $this->branch = (string) $bank->branch;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $data = $this->validate();

        if ($this->editingId) {
            Bank::findOrFail($this->editingId)->update($data);
            session()->flash('status', 'Bank updated.');
        } else {
            Bank::create($data);
            session()->flash('status', 'Bank added.');
        }

        unset($this->banks);
        $this->showForm = false;
        $this->editingId = null;
        $this->resetFields();
    }

    /**
     * Banks are deactivated rather than deleted — they drop out of the
     * payment dropdowns, but every payment already recorded against them
     * keeps reading correctly.
     */
    public function toggleActive(int $bankId): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);

        $bank = Bank::findOrFail($bankId);
        $bank->update(['is_active' => ! $bank->is_active]);

        unset($this->banks);
    }

    private function resetFields(): void
    {
        $this->reset(['name', 'account_title', 'account_number', 'branch']);
        $this->resetErrorBag();
    }
} ?>

@slot('header')
    <h1 class="text-xl font-semibold" style="color: var(--theme-bg-text)">Settings</h1>
    <p class="text-sm opacity-70" style="color: var(--theme-bg-text)">Banks and wallets you take payments through</p>
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
        <div class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2"
             style="color: var(--theme-accent); border-color: var(--theme-accent)">
            <x-tenant-icon name="building-storefront" class="h-4 w-4" />
            Banks
        </div>
        <a href="{{ route('tenant.settings.emi') }}" wire:navigate
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
            <x-tenant-icon name="calculator" class="h-4 w-4" />
            EMI Settings
        </a>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
        <div class="flex items-center justify-between p-5 pb-0">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">Banks &amp; Wallets</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">These appear in every payment method dropdown, alongside Cash.</p>
            </div>
            <button type="button" wire:click="toggleForm"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium
                       {{ $showForm
                            ? 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'
                            : 'bg-walnut-600 text-white hover:bg-walnut-400' }}">
                @unless ($showForm)
                    <x-tenant-icon name="plus" class="h-4 w-4" />
                @endunless
                {{ $showForm ? 'Cancel' : 'Add Bank' }}
            </button>
        </div>

        @if ($showForm)
            <form wire:submit="save" class="mx-5 mt-4 rounded-xl bg-gray-50 dark:bg-gray-900/40 p-4 space-y-4"
                  wire:loading.class="opacity-50 pointer-events-none" wire:target="save">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $editingId ? 'Edit Bank' : 'New Bank' }}</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label value="Name" />
                        <x-text-input wire:model="name" placeholder="e.g. Meezan Bank, EasyPaisa" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Account Title" />
                        <x-text-input wire:model="account_title" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('account_title')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Account Number" />
                        <x-text-input wire:model="account_number" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('account_number')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Branch" />
                        <x-text-input wire:model="branch" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('branch')" class="mt-1" />
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-walnut-600 px-5 py-2 text-sm font-medium text-white hover:bg-walnut-400 disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update Bank' : 'Save Bank' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                    <button type="button" wire:click="toggleForm" wire:loading.attr="disabled" wire:target="save"
                        class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 disabled:opacity-50">
                        Cancel
                    </button>
                </div>
            </form>
        @endif

        <div class="overflow-x-auto mt-4">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Name</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Account Title</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Account Number</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Branch</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->banks as $bank)
                        <tr class="{{ $bank->is_active ? '' : 'opacity-60' }}">
                            <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $bank->name }}</td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $bank->account_title ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ $bank->account_number ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $bank->branch ?: '—' }}</td>
                            <td class="px-4 py-2.5"><x-status-badge :status="$bank->is_active ? 'active' : 'suspended'" /></td>
                            <td class="px-4 py-2.5 text-right space-x-3 whitespace-nowrap">
                                <button type="button" wire:click="startEdit({{ $bank->id }})" class="text-walnut-400 hover:text-walnut-200 font-medium text-xs">Edit</button>
                                <button type="button" wire:click="toggleActive({{ $bank->id }})"
                                    wire:confirm="{{ $bank->is_active ? 'Hide this bank from payment dropdowns? Past payments keep showing it.' : 'Make this bank selectable again?' }}"
                                    class="{{ $bank->is_active ? 'text-rose-500 hover:text-rose-400' : 'text-emerald-600 hover:text-emerald-500' }} font-medium text-xs">
                                    {{ $bank->is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No banks yet — payments can only be recorded as Cash.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
