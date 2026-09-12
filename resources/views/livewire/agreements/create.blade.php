<?php

use App\Livewire\Concerns\HandlesReceiptProof;
use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Guarantor;
use App\Models\InstallmentSchedule;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\EmiCalculator;
use App\Support\PaymentMethod;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.tenant')] class extends Component
{
    use HandlesReceiptProof, WithFileUploads;

    #[Validate('required|exists:customers,id')]
    public ?int $customer_id = null;

    #[Validate('required|exists:products,id')]
    public ?int $product_id = null;

    public ?int $product_serial_id = null;

    public ?int $purchase_order_item_id = null;

    #[Validate('required|exists:users,id')]
    public ?int $salesman_id = null;

    #[Validate('required|integer|min:1')]
    public string $productPrice = '0';

    public string $downPaymentType = 'fixed';

    #[Validate('required|numeric|min:0')]
    public string $downPaymentValue = '0';

    // Set once a salesman types their own figure, so picking a different
    // serial (which re-reads the price) stops overwriting what they entered.
    public bool $downPaymentTouched = false;

    public string $downPaymentMode = PaymentMethod::CASH;

    public ?int $downPaymentBankId = null;

    #[Validate('required|numeric|min:0')]
    public string $interestRate = '12';

    #[Validate('required|integer|min:0')]
    public string $processingFee = '0';

    #[Validate('required|integer|min:1|max:36')]
    public ?int $durationMonths = 12;

    #[Validate('required|date')]
    public string $startDate = '';

    #[Validate('required|string|max:255')]
    public string $guarantor1_name = '';

    #[Validate('required|string|max:20')]
    public string $guarantor1_cnic = '';

    #[Validate('required|string|max:30')]
    public string $guarantor1_mobile = '';

    #[Validate('required|string|max:100')]
    public string $guarantor1_relation = '';

    public string $guarantor1_address = '';

    // Guarantor 2 is optional — only required to be complete once any one of
    // its fields is filled in (see save()), so no #[Validate] attributes here.
    public string $guarantor2_name = '';

    public string $guarantor2_cnic = '';

    public string $guarantor2_mobile = '';

    public string $guarantor2_relation = '';

    public string $guarantor2_address = '';

    private function guarantor2Provided(): bool
    {
        return trim($this->guarantor2_name) !== ''
            || trim($this->guarantor2_cnic) !== ''
            || trim($this->guarantor2_mobile) !== ''
            || trim($this->guarantor2_relation) !== ''
            || trim($this->guarantor2_address) !== '';
    }

    public function mount(): void
    {
        $shop = \App\Support\Tenant::current();
        $this->interestRate = (string) ($shop?->default_interest_rate ?? '12');
        // processingFee validates as `integer` — the shop setting is a decimal-cast
        // attribute (e.g. "500.00"), which fails that rule if left untouched.
        $this->processingFee = $this->wholeRupees($shop?->default_processing_fee ?? '0');
        $this->startDate = now()->toDateString();
        $this->salesman_id = auth()->user()->hasRole('Salesman') ? auth()->id() : null;
    }

    public function updatedProductId(): void
    {
        $product = Product::find($this->product_id);
        $basis = \App\Support\Tenant::current()?->emi_price_basis ?? 'selling';
        $this->productPrice = $product ? $this->wholeRupees($basis === 'cost' ? $product->cost_price : $product->cash_price) : '0';
        $this->product_serial_id = null;
        $this->purchase_order_item_id = null;
        $this->applyDefaultDownPayment();
    }

    /**
     * Down payments are entered in rupees, but the shop's usual deposit is
     * still a fifth of the price — so the field is seeded with that in rupees
     * as soon as a price is known, and left alone once anyone edits it.
     */
    private function applyDefaultDownPayment(): void
    {
        if ($this->downPaymentTouched) {
            return;
        }

        $this->downPaymentValue = $this->wholeRupees(
            bcdiv(bcmul($this->numeric($this->productPrice), '20', 4), '100', 2)
        );
    }

    public function updatedDownPaymentValue(): void
    {
        $this->downPaymentTouched = true;

        if (trim($this->downPaymentValue) === '') {
            $this->downPaymentValue = '0';
        }
    }

    public function updatedDurationMonths(): void
    {
        $this->validateOnly('durationMonths');
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'durationMonths.required' => 'Enter a duration between 1 and 36 months.',
            'durationMonths.integer' => 'Duration must be a whole number of months.',
            'durationMonths.min' => 'Duration must be at least 1 month.',
            'durationMonths.max' => 'Duration cannot be more than 36 months.',
        ];
    }

    /** Picking a specific unit/batch means we know its actual purchase price — use that over the product's shop-wide default. */
    private function applyBatchPrice(?PurchaseOrderItem $batch): void
    {
        if (! $batch) {
            return;
        }

        $basis = \App\Support\Tenant::current()?->emi_price_basis ?? 'selling';
        $this->productPrice = $this->wholeRupees($basis === 'cost' ? $batch->cost_price : $batch->selling_cash_price);
        $this->applyDefaultDownPayment();
    }

    /** productPrice validates as `integer` — decimal-cast model attributes (e.g. "69999.00") fail that rule, so strip to a plain whole-rupee string. */
    private function wholeRupees(string|float $value): string
    {
        return (string) (int) round((float) $value);
    }

    public function updatedProductSerialId(): void
    {
        $serial = $this->availableSerials->firstWhere('id', $this->product_serial_id);

        $this->applyBatchPrice($serial?->purchaseOrderItem);
    }

    public function updatedPurchaseOrderItemId(): void
    {
        $this->applyBatchPrice($this->availableBatches->firstWhere('id', $this->purchase_order_item_id));
    }

    /** @return array<int, array{id: int, label: string, sublabel: string}> */
    public function searchCustomers(string $query): array
    {
        if (mb_strlen($query) < 2) {
            return [];
        }

        return Customer::where('is_active', true)
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$query}%")
                ->orWhere('last_name', 'like', "%{$query}%")
                ->orWhere('cnic_number', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%"))
            ->orderBy('first_name')
            ->limit(15)
            ->get()
            ->map(fn ($customer) => [
                'id' => $customer->id,
                'label' => "{$customer->first_name} {$customer->last_name}",
                'sublabel' => "{$customer->cnic_number} · {$customer->phone}",
            ])
            ->all();
    }

    /** @return array<int, array{id: int, label: string, sublabel: string}> */
    public function searchProducts(string $query): array
    {
        if (mb_strlen($query) < 2) {
            return [];
        }

        return Product::where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', "%{$query}%")
                ->orWhere('sku', 'like', "%{$query}%")
                ->orWhere('brand', 'like', "%{$query}%"))
            ->orderBy('name')
            ->limit(15)
            ->get()
            ->map(fn ($product) => [
                'id' => $product->id,
                'label' => $product->name,
                'sublabel' => 'Rs. '.number_format((float) $product->cash_price, 0).($product->sku ? " · {$product->sku}" : ''),
            ])
            ->all();
    }

    #[Computed]
    public function salesmen()
    {
        // User isn't tenant-scoped by ShopScope (it must be queryable outside
        // a tenant context, e.g. at login) — filter explicitly here instead.
        return User::where('shop_id', \App\Support\Tenant::id())
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Salesman', 'Shop Admin', 'Manager']))
            ->orderBy('name')->get();
    }

    #[Computed]
    public function availableSerials()
    {
        if (! $this->product_id) {
            return collect();
        }

        return ProductSerial::where('product_id', $this->product_id)->where('status', 'in_stock')->get();
    }

    /**
     * Purchase batches for this product that still have unsold stock — lets
     * a non-serialized product (no individual IMEI to pick) still be sold
     * against the specific vendor/price it was actually bought at.
     */
    #[Computed]
    public function availableBatches()
    {
        if (! $this->product_id) {
            return collect();
        }

        return PurchaseOrderItem::where('product_id', $this->product_id)
            ->with('purchaseOrder.vendor')
            ->withSum('agreementItems as sold_quantity', 'quantity')
            ->oldest()
            ->get()
            ->filter(fn ($batch) => $batch->quantity - ($batch->sold_quantity ?? 0) > 0)
            ->values();
    }

    #[Computed]
    public function selectedProduct(): ?Product
    {
        return $this->product_id ? Product::find($this->product_id) : null;
    }

    private function numeric(?string $value): string
    {
        return $value === null || $value === '' || ! is_numeric($value) ? '0' : $value;
    }

    #[Computed]
    public function downPaymentAmount(): string
    {
        $price = $this->numeric($this->productPrice);

        if ($this->downPaymentType === 'percent') {
            return bcdiv(bcmul($price, $this->numeric($this->downPaymentValue), 4), '100', 2);
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
            durationMonths: $this->durationMonths ?? 0,
        );
    }

    /**
     * The summary has nothing to say until there's a duration to divide by —
     * it shows dashes rather than a momentary, meaningless "Rs. 0".
     */
    #[Computed]
    public function hasDuration(): bool
    {
        return $this->durationMonths !== null && $this->durationMonths >= 1;
    }

    /**
     * Shops either collect installment #1 on the agreement's start date or a
     * month later — Settings → EMI Settings.
     */
    public function firstDueNextMonth(): bool
    {
        return Tenant::current()?->firstDueNextMonth() ?? false;
    }

    /**
     * Shown under the Start Date field so whoever is filling in the form can
     * see the date the customer will actually be asked to pay on.
     */
    #[Computed]
    public function firstDueDate(): ?string
    {
        if (! $this->startDate) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($this->startDate)
            ->addMonthsNoOverflow($this->firstDueNextMonth() ? 1 : 0)
            ->format('d M Y');
    }

    public function save(): void
    {
        $this->validate();
        $this->validate([
            'downPaymentMode' => PaymentMethod::methodRule(),
            'downPaymentBankId' => PaymentMethod::bankRule('downPaymentMode'),
            ...$this->receiptProofRules(),
        ], PaymentMethod::bankMessages('downPaymentBankId'));

        if ($this->guarantor2Provided()) {
            $this->validate([
                'guarantor2_name' => 'required|string|max:255',
                'guarantor2_cnic' => 'required|string|max:20',
                'guarantor2_mobile' => 'required|string|max:30',
                'guarantor2_relation' => 'required|string|max:100',
            ]);
        }

        // The `exists:table,id` validation rules above run a plain DB query and
        // don't see ShopScope, so they'd accept another shop's customer/product
        // id. Re-fetch each one through its tenant-scoped Eloquent model (which
        // does apply ShopScope) to make sure it actually belongs to this shop.
        $customer = Customer::find($this->customer_id);
        $product = $this->selectedProduct;
        $salesman = User::where('shop_id', \App\Support\Tenant::id())->find($this->salesman_id);

        if (! $customer) {
            $this->addError('customer_id', 'Select a valid customer.');

            return;
        }

        if (! $product) {
            $this->addError('product_id', 'Select a valid product.');

            return;
        }

        if (! $salesman) {
            $this->addError('salesman_id', 'Select a valid salesman.');

            return;
        }

        if ($product->is_serialized && ! $this->product_serial_id) {
            $this->addError('product_serial_id', 'Select a serial/IMEI unit for this product.');

            return;
        }

        if ($this->product_serial_id && ! $this->availableSerials->contains('id', $this->product_serial_id)) {
            $this->addError('product_serial_id', 'That serial/IMEI unit is no longer available for this product.');

            return;
        }

        if ($this->purchase_order_item_id && ! $this->availableBatches->contains('id', $this->purchase_order_item_id)) {
            $this->addError('purchase_order_item_id', 'That purchase batch is no longer available for this product.');

            return;
        }

        if (bccomp($this->downPaymentAmount, $this->numeric($this->productPrice), 2) >= 0) {
            $this->addError('downPaymentValue', 'Down payment must be less than the product price.');

            return;
        }

        [$downPaymentMode, $downPaymentBankId] = PaymentMethod::toStorage($this->downPaymentMode, $this->downPaymentBankId);

        $agreement = DB::transaction(function () use ($downPaymentMode, $downPaymentBankId) {
            $calculator = $this->calculator;
            $schedule = $calculator->schedule($this->startDate, $this->firstDueNextMonth());

            $agreement = Agreement::create([
                'customer_id' => $this->customer_id,
                'salesman_id' => $this->salesman_id,
                'agreement_number' => 'AGR-'.now()->format('ymd').'-'.random_int(1000, 9999),
                'status' => 'pending_approval',
                'product_price' => $this->numeric($this->productPrice),
                'down_payment' => $this->downPaymentAmount,
                'processing_fee' => $this->numeric($this->processingFee),
                'interest_rate' => $this->numeric($this->interestRate),
                'duration_months' => $this->durationMonths,
                'financed_amount' => $calculator->financedAmount(),
                'total_interest' => $calculator->totalInterest(),
                'total_payable' => $calculator->totalPayable(),
                'monthly_installment' => $calculator->monthlyInstallment(),
                'start_date' => $this->startDate,
                'first_due_date' => $schedule[0]['due_date'] ?? null,
            ]);

            AgreementItem::create([
                'agreement_id' => $agreement->id,
                'product_id' => $this->product_id,
                'product_serial_id' => $this->product_serial_id,
                'purchase_order_item_id' => $this->product_serial_id ? null : $this->purchase_order_item_id,
                'quantity' => 1,
                'unit_price' => $this->numeric($this->productPrice),
            ]);

            if ($this->product_serial_id) {
                ProductSerial::whereKey($this->product_serial_id)->update([
                    'status' => 'sold',
                    'sold_at' => now(),
                ]);
            }

            foreach ($schedule as $row) {
                InstallmentSchedule::create(array_merge($row, ['agreement_id' => $agreement->id]));
            }

            Guarantor::create([
                'agreement_id' => $agreement->id,
                'name' => $this->guarantor1_name,
                'cnic_number' => $this->guarantor1_cnic,
                'mobile_number' => $this->guarantor1_mobile,
                'relation' => $this->guarantor1_relation,
                'work_address' => $this->guarantor1_address ?: null,
            ]);

            if ($this->guarantor2Provided()) {
                Guarantor::create([
                    'agreement_id' => $agreement->id,
                    'name' => $this->guarantor2_name,
                    'cnic_number' => $this->guarantor2_cnic,
                    'mobile_number' => $this->guarantor2_mobile,
                    'relation' => $this->guarantor2_relation,
                    'work_address' => $this->guarantor2_address ?: null,
                ]);
            }

            // The full obligation the customer signed up for — product price
            // plus processing fee and interest — shown as one debit, with the
            // down payment recorded as its own real Payment (so it shows up
            // in "Collected" totals, payment history, and receipts, just
            // like any other payment) and a matching ledger credit.
            $totalObligation = bcadd($calculator->totalPayable(), $this->downPaymentAmount, 2);

            CustomerLedgerEntry::create([
                'customer_id' => $this->customer_id,
                'agreement_id' => $agreement->id,
                'type' => 'debit',
                'amount' => $totalObligation,
                'running_balance' => '0.00',
                'reference_type' => Agreement::class,
                'reference_id' => $agreement->id,
                'description' => "New agreement — {$agreement->ledgerLabel()}",
                'entry_date' => now()->toDateString(),
                'created_by' => auth()->id(),
            ]);

            if (bccomp($this->downPaymentAmount, '0', 2) > 0) {
                $downPayment = Payment::create([
                    'agreement_id' => $agreement->id,
                    'installment_schedule_id' => null,
                    'customer_id' => $this->customer_id,
                    'amount' => $this->downPaymentAmount,
                    'payment_mode' => $downPaymentMode,
                    'bank_id' => $downPaymentBankId,
                    'received_by' => auth()->id(),
                    'receipt_number' => 'RCP-'.$agreement->shop_id.'-'.now()->format('ymd').'-'.random_int(1000, 9999),
                    'paid_at' => now(),
                    'notes' => "Down payment for {$agreement->agreement_number}",
                ]);

                CustomerLedgerEntry::create([
                    'customer_id' => $this->customer_id,
                    'agreement_id' => $agreement->id,
                    'type' => 'credit',
                    'amount' => $this->downPaymentAmount,
                    'payment_mode' => $downPaymentMode,
                    'bank_id' => $downPaymentBankId,
                    'running_balance' => '0.00',
                    'reference_type' => Payment::class,
                    'reference_id' => $downPayment->id,
                    'description' => "Down payment received — {$agreement->ledgerLabel()}",
                    'entry_date' => now()->toDateString(),
                    'created_by' => auth()->id(),
                ]);

                $this->storeReceiptProof($downPayment);
            }

            CustomerLedgerEntry::recalculateFor($this->customer_id);

            return $agreement;
        });

        session()->flash('status', "Agreement {$agreement->agreement_number} created and awaiting approval.");

        $this->redirect(
            \Illuminate\Support\Facades\Route::has('tenant.agreements.index') ? route('tenant.agreements.index') : '/',
            navigate: true
        );
    }
} ?>

@slot('header')
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">New Agreement</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Created agreements start as Pending Approval</p>
    @endslot

<div>

    <form wire:submit="save" class="max-w-4xl space-y-6" wire:loading.class="opacity-50 pointer-events-none" wire:target="save">
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Customer & Product</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="customer_id" value="Customer" />
                    <x-search-select search-method="searchCustomers" model="customer_id"
                        placeholder="Search by name, CNIC, or phone…" class="mt-1" />
                    <x-input-error :messages="$errors->get('customer_id')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="salesman_id" value="Salesman" />
                    <select id="salesman_id" wire:model="salesman_id" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <option value="">Select salesman…</option>
                        @foreach ($this->salesmen as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('salesman_id')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="product_id" value="Product" />
                    <x-search-select search-method="searchProducts" model="product_id"
                        placeholder="Search by name, SKU, or brand…" class="mt-1" />
                    <x-input-error :messages="$errors->get('product_id')" class="mt-1" />
                </div>

                @if ($this->selectedProduct?->is_serialized)
                    <div wire:key="serial-field-{{ $product_id }}">
                        <x-input-label for="product_serial_id" value="Serial / IMEI" />
                        <x-local-select
                            :options="$this->availableSerials->map(fn ($serial) => ['id' => $serial->id, 'label' => $serial->serial_number])->values()->all()"
                            model="product_serial_id"
                            placeholder="Click to browse or search serial/IMEI…" class="mt-1" />
                        <x-input-error :messages="$errors->get('product_serial_id')" class="mt-1" />
                        @if ($this->availableSerials->isEmpty())
                            <p class="text-xs text-rose-500 mt-1">No in-stock units for this product.</p>
                        @endif
                    </div>
                @elseif ($this->selectedProduct && $this->availableBatches->isNotEmpty())
                    <div wire:key="batch-field-{{ $product_id }}">
                        <x-input-label for="purchase_order_item_id" value="Purchase Batch" />
                        <x-local-select
                            :options="$this->availableBatches->map(fn ($batch) => [
                                'id' => $batch->id,
                                'label' => ($batch->purchaseOrder->vendor->name ?? 'Unknown vendor').' — Rs. '.number_format((float) $batch->cost_price, 0).' cost ('.($batch->quantity - ($batch->sold_quantity ?? 0)).' left)',
                            ])->values()->all()"
                            model="purchase_order_item_id"
                            placeholder="This model was bought from more than one vendor — pick which batch you're selling…" class="mt-1" />
                        <x-input-error :messages="$errors->get('purchase_order_item_id')" class="mt-1" />
                        <p class="text-xs text-gray-400 mt-1">Optional — bought from more than one vendor at different prices? Pick the batch this unit came from.</p>
                    </div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
                <h3 class="font-semibold text-gray-900 dark:text-white">EMI Terms</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="productPrice" value="Product Price" />
                        <x-text-input id="productPrice" type="number" step="1" wire:model.live="productPrice" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('productPrice')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Down Payment" />
                        <div class="mt-1 flex rounded-lg shadow-sm">
                            <input type="number" step="0.01" wire:model.live="downPaymentValue" onfocus="this.select()"
                                class="block w-full rounded-l-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-walnut-400 focus:ring-walnut-400">
                            <select wire:model.live="downPaymentType" class="rounded-r-lg border-l-0 border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="fixed">Rs.</option>
                                <option value="percent">%</option>
                            </select>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">≈ Rs. {{ number_format((float) $this->downPaymentAmount, 0) }}</p>
                        <x-input-error :messages="$errors->get('downPaymentValue')" class="mt-1" />
                    </div>
                    <x-payment-method-select method-model="downPaymentMode" bank-model="downPaymentBankId" label="Down Payment Method" />
                    <div>
                        <x-input-label for="interestRate" value="Interest Rate (annual %)" />
                        <x-text-input id="interestRate" type="number" step="0.01" wire:model.live="interestRate" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="processingFee" value="Processing Fee" />
                        <x-text-input id="processingFee" type="number" step="1" wire:model.live="processingFee" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="startDate" value="Start Date" />
                        <x-text-input id="startDate" type="date" wire:model.live="startDate" class="mt-1 block w-full" />
                        @if ($this->firstDueDate)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                First installment due {{ $this->firstDueDate }}{{ $this->firstDueNextMonth() ? ' (a month after the start date)' : '' }}
                            </p>
                        @endif
                    </div>
                </div>

                <div>
                    <x-input-label for="durationMonths" value="Duration (months)" />
                    <x-text-input id="durationMonths" type="number" min="1" max="36" step="1"
                        wire:model.live="durationMonths" class="mt-1 block w-full sm:w-32" />
                    <p class="mt-1 text-xs text-gray-400">1–36 months</p>
                    <x-input-error :messages="$errors->get('durationMonths')" class="mt-1" />
                </div>
            </div>

            <div class="rounded-2xl border border-walnut-200/60 dark:border-walnut-900/60 bg-walnut-50/70 dark:bg-walnut-900/40 backdrop-blur-xl shadow-lg p-5 space-y-4">
                <h3 class="font-semibold text-walnut-900 dark:text-walnut-200">Summary</h3>
                @php
                    $money = fn ($value) => $this->hasDuration ? 'Rs. '.number_format((float) $value, 0) : '—';
                @endphp
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Down Payment</dt><dd class="font-medium text-walnut-900 dark:text-walnut-200">{{ $money($this->downPaymentAmount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Financed</dt><dd class="font-medium text-walnut-900 dark:text-walnut-200">{{ $money($this->calculator->financedAmount()) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Total Interest</dt><dd class="font-medium text-walnut-900 dark:text-walnut-200">{{ $money($this->calculator->totalInterest()) }}</dd></div>
                    <div class="flex justify-between border-t border-walnut-200 dark:border-walnut-900 pt-3"><dt class="text-walnut-600/70 dark:text-walnut-200/70">Total Payable</dt><dd class="font-semibold text-walnut-900 dark:text-walnut-200">{{ $money($this->calculator->totalPayable()) }}</dd></div>
                </dl>
                <div class="rounded-xl bg-white/70 dark:bg-gray-900/50 p-4 text-center">
                    <p class="text-xs text-walnut-600/70 dark:text-walnut-200/70">Monthly Installment</p>
                    <p class="text-3xl font-bold text-walnut-600 dark:text-walnut-200">{{ $money($this->calculator->monthlyInstallment()) }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4">
            <h3 class="font-semibold text-gray-900 dark:text-white">Guarantors <span class="text-xs font-normal text-gray-400">(at least 1 required)</span></h3>

            @foreach ([1, 2] as $n)
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 {{ $n === 2 ? 'border-t border-gray-100 dark:border-gray-700 pt-4' : '' }}">
                    <p class="sm:col-span-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        Guarantor {{ $n }}
                        @if ($n === 2)
                            <span class="font-normal text-gray-400">(optional)</span>
                        @endif
                    </p>
                    <div>
                        <x-input-label value="Full Name" />
                        <x-text-input wire:model="guarantor{{ $n }}_name" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('guarantor'.$n.'_name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="CNIC Number" />
                        <x-text-input wire:model="guarantor{{ $n }}_cnic" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('guarantor'.$n.'_cnic')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Mobile Number" />
                        <x-text-input wire:model="guarantor{{ $n }}_mobile" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('guarantor'.$n.'_mobile')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Relation" />
                        <x-text-input wire:model="guarantor{{ $n }}_relation" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('guarantor'.$n.'_relation')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label value="Work Address" />
                        <x-text-input wire:model="guarantor{{ $n }}_address" class="mt-1 block w-full" />
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-walnut-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-walnut-400 disabled:opacity-50 disabled:cursor-not-allowed">
                <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span wire:loading.remove wire:target="save">Create Agreement</span>
                <span wire:loading wire:target="save">Creating…</span>
            </button>
            <a href="{{ \Illuminate\Support\Facades\Route::has('tenant.agreements.index') ? route('tenant.agreements.index') : '/' }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
    </form>
</div>
