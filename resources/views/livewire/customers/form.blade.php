<?php

use App\Models\Customer;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.tenant')] class extends Component
{
    use WithFileUploads;

    public ?Customer $customer = null;

    #[Validate('required|string|max:100')]
    public string $first_name = '';

    #[Validate('required|string|max:100')]
    public string $last_name = '';

    #[Validate('required|string|max:20')]
    public string $cnic_number = '';

    #[Validate('required|string|max:30')]
    public string $phone = '';

    public string $alternate_phone = '';

    public string $email = '';

    public string $address = '';

    public string $city = '';

    public string $occupation = '';

    public string $employer_name = '';

    public string $monthly_income = '';

    public string $home_ownership = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $notes = '';

    #[Validate([
        'newDocuments' => 'array|max:5',
        'newDocuments.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:4096',
    ])]
    public array $newDocuments = [];

    public function mount(?Customer $customer = null): void
    {
        if ($customer?->exists) {
            $this->customer = $customer;
            // Every field here but the first 4 is a nullable column — cast
            // null to '' before fill(), since these are typed `string` (not
            // `?string`) properties and Livewire assigns them by direct
            // property access, which throws a TypeError on a null value.
            $this->fill(collect($customer->only([
                'first_name', 'last_name', 'cnic_number', 'phone', 'alternate_phone', 'email',
                'address', 'city', 'occupation', 'employer_name', 'monthly_income',
                'home_ownership', 'date_of_birth', 'gender', 'notes',
            ]))->map(fn ($value) => $value ?? '')->all());
        }
    }

    public function save(): void
    {
        $this->validate([
            'cnic_number' => [
                'required', 'string', 'max:20',
                Rule::unique('customers', 'cnic_number')
                    ->where('shop_id', auth()->user()->shop_id)
                    ->ignore($this->customer?->id),
            ],
        ]);
        $this->validate();

        $data = collect($this->all())->only([
            'first_name', 'last_name', 'cnic_number', 'phone', 'alternate_phone', 'email',
            'address', 'city', 'occupation', 'employer_name', 'monthly_income',
            'home_ownership', 'date_of_birth', 'gender', 'notes',
        ])->map(fn ($v) => $v === '' ? null : $v)->toArray();

        $this->customer = $this->customer
            ? tap($this->customer)->update($data)
            : Customer::create($data);

        $existing = $this->customer->getMedia(Customer::MEDIA_COLLECTION)->count();

        foreach ($this->newDocuments as $file) {
            if ($existing >= Customer::MAX_MEDIA_FILES) {
                break;
            }

            $this->customer->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection(Customer::MEDIA_COLLECTION);

            $existing++;
        }

        $this->newDocuments = [];

        session()->flash('status', 'Customer saved.');

        $this->redirect(
            \Illuminate\Support\Facades\Route::has('tenant.customers.index') ? route('tenant.customers.index') : '/',
            navigate: true
        );
    }

    public function removeMedia(int $mediaId): void
    {
        $this->customer?->media()->whereKey($mediaId)->first()?->delete();
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">
            {{ $customer?->exists ? 'Edit Customer' : 'New Customer' }}
        </h1>
    @endslot

<div>

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Personal Information</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="first_name" value="First Name" />
                    <x-text-input id="first_name" wire:model="first_name" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('first_name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="last_name" value="Last Name" />
                    <x-text-input id="last_name" wire:model="last_name" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('last_name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="cnic_number" value="CNIC / ID Number" />
                    <x-text-input id="cnic_number" wire:model="cnic_number" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('cnic_number')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="date_of_birth" value="Date of Birth" />
                    <x-text-input id="date_of_birth" type="date" wire:model="date_of_birth" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="phone" value="Phone" />
                    <x-text-input id="phone" wire:model="phone" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="alternate_phone" value="Alternate Phone" />
                    <x-text-input id="alternate_phone" wire:model="alternate_phone" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" type="email" wire:model="email" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="gender" value="Gender" />
                    <select id="gender" wire:model="gender" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <option value="">—</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="address" value="Address" />
                    <textarea id="address" wire:model="address" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400"></textarea>
                </div>
                <div>
                    <x-input-label for="city" value="City" />
                    <x-text-input id="city" wire:model="city" class="mt-1 block w-full" />
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Employment & Income</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="occupation" value="Occupation" />
                    <x-text-input id="occupation" wire:model="occupation" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="employer_name" value="Employer Name" />
                    <x-text-input id="employer_name" wire:model="employer_name" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="monthly_income" value="Verified Monthly Income" />
                    <x-text-input id="monthly_income" type="number" step="0.01" wire:model="monthly_income" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="home_ownership" value="Home Ownership" />
                    <select id="home_ownership" wire:model="home_ownership" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <option value="">—</option>
                        <option value="owned">Owned</option>
                        <option value="rented">Rented</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="notes" value="Notes" />
                    <textarea id="notes" wire:model="notes" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400"></textarea>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Documents</h3>
            <p class="text-xs text-gray-400 mb-3">CNIC/ID Front, CNIC/ID Back, Customer Photo, Proof of Income, Guarantor Photo — up to 5 files.</p>

            <x-media-dropzone
                model="newDocuments"
                :existing="$customer?->getMedia(\App\Models\Customer::MEDIA_COLLECTION) ?? []"
                :max-files="\App\Models\Customer::MAX_MEDIA_FILES"
                :max-size-mb="4"
                accept="image/png,image/jpeg,image/webp,application/pdf"
                remove-method="removeMedia"
                label="KYC Documents"
            />
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-walnut-400" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save Customer</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.customers.index') ? route('tenant.customers.index') : '/' }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
    </form>
</div>
