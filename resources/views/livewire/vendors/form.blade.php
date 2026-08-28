<?php

use App\Models\Vendor;
use Illuminate\Support\Facades\Redirect;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithFileUploads;

    public ?Vendor $vendor = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $contact_person = '';

    #[Validate('required|string|max:30')]
    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $bank_name = '';

    public string $bank_account_title = '';

    public string $bank_account_number = '';

    public string $tax_number = '';

    public string $notes = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    #[Validate([
        'newDocuments' => 'array|max:5',
        'newDocuments.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:4096',
    ])]
    public array $newDocuments = [];

    public function mount(?Vendor $vendor = null): void
    {
        abort_unless(auth()->user()->can('manage-vendors'), 403);

        if ($vendor?->exists) {
            $this->vendor = $vendor;
            // Nullable columns cast to '' before fill() — these are typed
            // `string` properties, and Livewire's direct property assignment
            // throws a TypeError on a null value.
            $this->fill(collect($vendor->only([
                'name', 'contact_person', 'phone', 'email', 'address',
                'bank_name', 'bank_account_title', 'bank_account_number', 'tax_number', 'notes',
            ]))->map(fn ($value) => $value ?? '')->all());
        }
    }

    public function save(): void
    {
        $this->validate();

        if ($this->vendor?->hasReachedMediaLimit(Vendor::MEDIA_COLLECTION, Vendor::MAX_MEDIA_FILES)
            && count($this->newDocuments) > 0) {
            $this->addError('newDocuments', 'This vendor already has the maximum of 5 documents.');

            return;
        }

        $data = collect($this->all())->only([
            'name', 'contact_person', 'phone', 'email', 'address',
            'bank_name', 'bank_account_title', 'bank_account_number', 'tax_number', 'notes',
        ])->toArray();

        $this->vendor = $this->vendor
            ? tap($this->vendor)->update($data)
            : Vendor::create($data);

        $existing = $this->vendor->getMedia(Vendor::MEDIA_COLLECTION)->count();

        foreach ($this->newDocuments as $file) {
            if ($existing >= Vendor::MAX_MEDIA_FILES) {
                break;
            }

            $this->vendor->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection(Vendor::MEDIA_COLLECTION);

            $existing++;
        }

        $this->newDocuments = [];

        session()->flash('status', 'Vendor saved.');

        $this->redirect(
            \Illuminate\Support\Facades\Route::has('tenant.vendors.index') ? route('tenant.vendors.index') : '/',
            navigate: true
        );
    }

    public function removeMedia(int $mediaId): void
    {
        $this->vendor?->media()->whereKey($mediaId)->first()?->delete();
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">
            {{ $vendor?->exists ? 'Edit Vendor' : 'New Vendor' }}
        </h1>
    @endslot

<div>

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Profile</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="name" value="Vendor / Business Name" />
                    <x-text-input id="name" wire:model="name" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="contact_person" value="Contact Person" />
                    <x-text-input id="contact_person" wire:model="contact_person" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="phone" value="Phone" />
                    <x-text-input id="phone" wire:model="phone" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" type="email" wire:model="email" class="mt-1 block w-full" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="address" value="Address" />
                    <textarea id="address" wire:model="address" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400"></textarea>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Bank & Tax Details</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="bank_name" value="Bank Name" />
                    <x-text-input id="bank_name" wire:model="bank_name" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="bank_account_title" value="Account Title" />
                    <x-text-input id="bank_account_title" wire:model="bank_account_title" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="bank_account_number" value="Account Number / IBAN" />
                    <x-text-input id="bank_account_number" wire:model="bank_account_number" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="tax_number" value="Tax / NTN Number" />
                    <x-text-input id="tax_number" wire:model="tax_number" class="mt-1 block w-full" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="notes" value="Notes" />
                    <textarea id="notes" wire:model="notes" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400"></textarea>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Documents</h3>
            <p class="text-xs text-gray-400 mb-3">Logo, Trade License, Owner CNIC, Contract Copy, Storefront — up to 5 files.</p>

            <x-media-dropzone
                model="newDocuments"
                :existing="$vendor?->getMedia(\App\Models\Vendor::MEDIA_COLLECTION) ?? []"
                :max-files="\App\Models\Vendor::MAX_MEDIA_FILES"
                :max-size-mb="4"
                accept="image/png,image/jpeg,image/webp,application/pdf"
                remove-method="removeMedia"
                label="Vendor Documents"
            />
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-walnut-400" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save Vendor</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.vendors.index') ? route('tenant.vendors.index') : '/' }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
    </form>
</div>
