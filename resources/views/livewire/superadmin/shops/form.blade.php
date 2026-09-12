<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.super-admin')] class extends Component
{
    public ?Shop $shop = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $slug = '';

    /** Once true, typing in Shop Name stops overwriting the slug — set the moment the slug is edited by hand, or loaded from an existing shop. */
    public bool $slugManuallyEdited = false;

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $city = '';

    #[Validate('required|in:trial,active,suspended')]
    public string $subscription_status = 'trial';

    #[Validate('required|in:monthly,yearly')]
    public string $billing_cycle = 'monthly';

    #[Validate('required|integer|min:0')]
    public string $monthly_fee = '0';

    #[Validate('required|integer|min:0')]
    public string $annual_fee = '0';

    #[Validate('required|numeric|min:0')]
    public string $default_interest_rate = '12';

    #[Validate('required|integer|min:0')]
    public string $default_processing_fee = '0';

    #[Validate('required|in:daily,fixed')]
    public string $penalty_type = 'daily';

    #[Validate('required|integer|min:0')]
    public string $penalty_rate = '0';

    #[Validate('required|integer|min:0')]
    public int $grace_period_days = 0;

    // Owner account — only set/used when provisioning a new shop.
    public string $owner_name = '';

    public string $owner_email = '';

    public string $owner_password = '';

    public function mount(?Shop $shop = null): void
    {
        if ($shop?->exists) {
            $this->shop = $shop;
            $this->slug = $shop->slug;
            $this->slugManuallyEdited = true;
            // Nullable columns cast to '' before fill() — these are typed
            // `string` properties, and Livewire's direct property assignment
            // throws a TypeError on a null value. grace_period_days is int
            // (not string) and NOT NULL in the schema, so it passes through
            // unchanged.
            $this->fill([
                ...collect($shop->only([
                    'name', 'phone', 'email', 'address', 'city', 'subscription_status', 'billing_cycle',
                    'monthly_fee', 'annual_fee', 'default_interest_rate', 'default_processing_fee',
                    'penalty_type', 'penalty_rate',
                ]))->map(fn ($value) => $value ?? '')->all(),
                ...$shop->only(['grace_period_days']),
            ]);
        }
    }

    #[Computed]
    public function payments()
    {
        return $this->shop?->subscriptionPayments()->with('recorder')->latest('paid_on')->latest('id')->get() ?? collect();
    }

    public function updatedName(): void
    {
        if (! $this->slugManuallyEdited) {
            $this->slug = Str::slug($this->name);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugManuallyEdited = true;
        $this->slug = Str::slug($this->slug);
    }

    public function save(): void
    {
        $this->validate();

        $this->validate([
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('shops', 'slug')->ignore($this->shop?->id),
            ],
        ], [
            'slug.regex' => 'The URL slug may only contain lowercase letters, numbers, and hyphens.',
        ]);

        if (! $this->shop) {
            $this->validate([
                'owner_name' => 'required|string|max:255',
                'owner_email' => 'required|email|unique:users,email',
                'owner_password' => 'required|string|min:8',
            ]);
        }

        DB::transaction(function () {
            // EMI and penalty settings belong to the shop once it exists
            // (Settings -> EMI Settings). Writable here only at provisioning,
            // so a running shop's numbers can't be overwritten from this form,
            // tampered snapshot included.
            $shopOwnedFields = [
                'default_interest_rate', 'default_processing_fee',
                'penalty_type', 'penalty_rate', 'grace_period_days',
            ];

            $data = collect($this->all())->only([
                'name', 'slug', 'phone', 'email', 'address', 'city', 'subscription_status', 'billing_cycle',
                'monthly_fee', 'annual_fee',
                ...($this->shop ? [] : $shopOwnedFields),
            ])->toArray();

            $paidUpfront = (int) $this->annual_fee > 0 && ! $this->shop;

            if ($this->shop) {
                $this->shop->update($data);
            } else {
                $data['trial_ends_at'] = $this->subscription_status === 'trial' ? now()->addDays(14) : null;

                // An annual fee entered at provisioning is money already
                // received, so the shop opens on a paid year starting today.
                if ($paidUpfront) {
                    $data['subscription_status'] = 'active';
                    $data['trial_ends_at'] = null;
                    $data['subscription_started_at'] = now();
                    $data['next_billing_date'] = today()->addYear();
                }

                $this->shop = Shop::create($data);

                if ($paidUpfront) {
                    $this->shop->subscriptionPayments()->create([
                        'amount' => $this->annual_fee,
                        'paid_on' => today(),
                        'period_start' => today(),
                        'period_end' => today()->addYear(),
                        'notes' => 'Initial subscription',
                        'recorded_by' => auth()->id(),
                    ]);
                }

                $owner = User::create([
                    'name' => $this->owner_name,
                    'email' => $this->owner_email,
                    'password' => bcrypt($this->owner_password),
                    'shop_id' => $this->shop->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
                $owner->assignRole('Shop Admin');

                $this->shop->update(['owner_user_id' => $owner->id]);
            }

            session()->flash('status', "Shop {$this->shop->name} saved.");

            $this->redirect(
                \Illuminate\Support\Facades\Route::has('superadmin.shops.index') ? route('superadmin.shops.index') : '/',
                navigate: true
            );
        });
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $shop?->exists ? 'Edit Shop' : 'Provision New Shop' }}</h1>
    @endslot

<div>

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Shop Details</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Shop Name</label>
                    <input wire:model.live.debounce.300ms="name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">URL Slug</label>
                    <input wire:model.live.debounce.300ms="slug" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400 font-mono text-sm">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Login URL: {{ url('/'.($slug ?: '{slug}').'/login') }}</p>
                    <x-input-error :messages="$errors->get('slug')" class="mt-1" />
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                    <input wire:model="phone" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input type="email" wire:model="email" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
                <div class="sm:col-span-2">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Address</label>
                    <input wire:model="address" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">City</label>
                    <input wire:model="city" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                </div>
            </div>
        </div>

        @unless ($shop?->exists)
            <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
                <h3 class="font-semibold text-gray-900 dark:text-white">Owner Account</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Owner Name</label>
                        <input wire:model="owner_name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <x-input-error :messages="$errors->get('owner_name')" class="mt-1" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Owner Email</label>
                        <input type="email" wire:model="owner_email" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <x-input-error :messages="$errors->get('owner_email')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Temporary Password</label>
                        <input type="password" wire:model="owner_password" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <x-input-error :messages="$errors->get('owner_password')" class="mt-1" />
                    </div>
                </div>
            </div>
        @endunless

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Subscription & Billing</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                    <select wire:model="subscription_status" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                        <option value="trial">Trial</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Billing Cycle</label>
                    <select wire:model="billing_cycle" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Monthly Fee</label>
                    <input type="number" step="1" wire:model="monthly_fee" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Annual Fee</label>
                    <input type="number" step="1" wire:model="annual_fee" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                    <p class="mt-1 text-xs text-gray-400">
                        @if ($shop?->exists)
                            The renewal amount suggested when reactivating this shop.
                        @else
                            Treated as paid today — the shop opens Active for one year and this is recorded as its first payment.
                        @endif
                    </p>
                </div>
            </div>

            @if ($shop?->exists)
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 rounded-xl bg-gray-50 dark:bg-gray-900/40 p-4">
                    <div>
                        <p class="text-xs text-gray-400">Paid to Date</p>
                        <p class="font-semibold text-emerald-600 dark:text-emerald-400">Rs. {{ number_format((float) $shop->subscriptionPayments()->sum('amount'), 0) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">Subscription Ends</p>
                        <p class="font-semibold text-gray-900 dark:text-white">{{ $shop->next_billing_date?->format('d M Y') ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">Payments Recorded</p>
                        <p class="font-semibold text-gray-900 dark:text-white">{{ $shop->subscriptionPayments()->count() }}</p>
                    </div>
                </div>
            @endif
        </div>

        @if ($shop?->exists)
            <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
                <h3 class="font-semibold text-gray-900 dark:text-white p-5 pb-0">Subscription Payments</h3>
                <div class="overflow-x-auto mt-4">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Paid On</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500">Amount</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Period Covered</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Recorded By</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($this->payments as $payment)
                                <tr>
                                    <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ $payment->paid_on->format('d M Y') }}</td>
                                    <td class="px-4 py-2.5 text-right font-medium text-gray-900 dark:text-white">Rs. {{ number_format((float) $payment->amount, 0) }}</td>
                                    <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $payment->period_start->format('d M Y') }} — {{ $payment->period_end->format('d M Y') }}</td>
                                    <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $payment->recorder?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $payment->notes ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No subscription payments recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">EMI &amp; Penalty Defaults</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if ($shop)
                        The shop manages these itself under Settings &rarr; EMI Settings. Shown here so you can see what it is running on.
                    @else
                        Starting values for the new shop. Once it is provisioned, its Shop Admin manages them under Settings &rarr; EMI Settings.
                    @endif
                </p>
            </div>

            @if ($shop)
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    @foreach ([
                        'Default Interest Rate' => $shop->default_interest_rate . '%',
                        'Default Processing Fee' => 'Rs. ' . number_format((float) $shop->default_processing_fee, 0),
                        'Penalty Type' => ucfirst($shop->penalty_type),
                        'Penalty Rate' => 'Rs. ' . number_format((float) $shop->penalty_rate, 0) . ($shop->penalty_type === 'daily' ? ' per day' : ''),
                        'Grace Period' => $shop->grace_period_days . ' ' . \Illuminate\Support\Str::plural('day', $shop->grace_period_days),
                    ] as $label => $value)
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 px-4 py-3">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Default Interest Rate (%)</label>
                        <input type="number" step="0.01" wire:model="default_interest_rate" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Default Processing Fee</label>
                        <input type="number" step="1" wire:model="default_processing_fee" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Penalty Type</label>
                        <select wire:model="penalty_type" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                            <option value="daily">Daily</option>
                            <option value="fixed">Fixed</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Penalty Rate</label>
                        <input type="number" step="1" wire:model="penalty_rate" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Grace Period (days)</label>
                        <input type="number" wire:model="grace_period_days" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm">
                    </div>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-walnut-400">Save Shop</button>
            <a href="{{ \Illuminate\Support\Facades\Route::has('superadmin.shops.index') ? route('superadmin.shops.index') : '/' }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">Cancel</a>
        </div>
    </form>
</div>
