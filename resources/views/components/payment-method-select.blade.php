@props([
    'methodModel',
    'bankModel',
    'label' => 'Payment Method',
    'withCredit' => false,
    // A bank transfer leaves a slip. Living here rather than on each form
    // means every screen that can say "Bank" asks for the proof the same way.
    'withReceiptProof' => true,
    'receiptProofModel' => 'receiptProof',
    'existingProof' => null,
    'proofUrl' => null,
    'removeProofMethod' => 'removeReceiptProof',
])

@php
    use App\Support\PaymentMethod;

    $banks = PaymentMethod::banks();
    $methods = PaymentMethod::methods($withCredit);

    // Each method keeps its own colour so the selection reads at a glance,
    // matching the Cash In / Cash Out toggle on the ledger forms.
    $styles = [
        PaymentMethod::CASH => ['label' => 'Cash', 'active' => 'bg-emerald-600 text-white border-emerald-600'],
        PaymentMethod::BANK => ['label' => 'Bank', 'active' => 'bg-sky-600 text-white border-sky-600'],
        PaymentMethod::CREDIT => ['label' => 'Credit', 'active' => 'bg-amber-500 text-white border-amber-500'],
    ];
    $idle = 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-gray-400';
@endphp

<div {{ $attributes }}
     x-data="{
         method: @js($this->{$methodModel}),
         bankId: @js((string) ($this->{$bankModel} ?? '')),
         banks: @js($banks->keyBy('id')->map(fn ($bank) => ['name' => $bank->name, 'details' => $bank->detailLine()])),
         get selectedBank() { return this.banks[this.bankId] ?? null; },
         choose(value) {
             if (value === 'bank' && ! Object.keys(this.banks).length) return;
             this.method = value;
         },
     }"
     x-init="
         $watch('method', value => {
             $wire.set(@js($methodModel), value, false);
             if (value !== 'bank') { bankId = ''; $wire.set(@js($bankModel), null, false); }
         });
         $watch('bankId', value => $wire.set(@js($bankModel), value === '' ? null : value, false));
     ">
    <x-input-label :value="$label" />

    <div class="mt-1 flex gap-2">
        @foreach ($methods as $method)
            @php($isBank = $method === PaymentMethod::BANK)
            <button type="button" @click="choose(@js($method))"
                @if ($isBank && $banks->isEmpty()) disabled @endif
                :class="method === @js($method) ? @js($styles[$method]['active']) : @js($idle)"
                class="flex-1 rounded-lg border px-4 py-2 text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed">
                {{ $styles[$method]['label'] }}
                @if ($method === PaymentMethod::CREDIT)
                    <span class="block text-[11px] font-normal opacity-80">pay vendor later</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($banks->isEmpty())
        <p class="mt-1 text-xs text-gray-400">
            No banks set up yet —
            <a href="{{ route('tenant.settings.banks') }}" wire:navigate class="text-walnut-400 hover:text-walnut-200">add one under Settings → Banks</a>
            to record bank payments.
        </p>
    @else
        <div x-show="method === 'bank'" x-cloak x-transition.opacity class="mt-2">
            <select x-model="bankId"
                class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                <option value="">Select a bank…</option>
                @foreach ($banks as $bank)
                    <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                @endforeach
            </select>
            <p x-show="selectedBank && selectedBank.details" x-cloak class="mt-1 text-xs text-gray-400" x-text="selectedBank?.details"></p>

            @if ($withReceiptProof)
                @php($pending = $this->{$receiptProofModel} ?? null)
                <div class="mt-3">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Receipt / proof of transfer</span>
                        <span class="text-[11px] text-gray-400">optional · JPG, PNG or PDF, max 4MB</span>
                    </div>

                    @if ($existingProof)
                        <div class="mt-1 flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2">
                            <x-tenant-icon name="paper-clip" class="h-4 w-4 shrink-0 text-gray-400" />
                            <a href="{{ $proofUrl }}" target="_blank" class="min-w-0 flex-1 truncate text-sm text-walnut-600 hover:underline dark:text-walnut-400">{{ $existingProof->file_name }}</a>
                            <button type="button" wire:click="{{ $removeProofMethod }}" class="shrink-0 text-xs font-medium text-rose-600 hover:underline">Remove</button>
                        </div>
                    @else
                        <input type="file" wire:model="{{ $receiptProofModel }}" accept="image/png,image/jpeg,image/webp,application/pdf"
                            class="mt-1 block w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:rounded-lg file:border-0 file:bg-sky-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-sky-700 hover:file:bg-sky-100 dark:file:bg-sky-900/40 dark:file:text-sky-200">
                        <p wire:loading wire:target="{{ $receiptProofModel }}" class="mt-1 text-xs text-gray-400">Uploading…</p>
                        @if ($pending)
                            <p wire:loading.remove wire:target="{{ $receiptProofModel }}" class="mt-1 flex items-center gap-1.5 text-xs text-emerald-600">
                                <x-tenant-icon name="check-circle" class="h-3.5 w-3.5" />
                                {{ $pending->getClientOriginalName() }} ready to attach
                            </p>
                        @endif
                    @endif

                    <x-input-error :messages="$errors->get($receiptProofModel)" class="mt-1" />
                </div>
            @endif
        </div>
    @endif

    <x-input-error :messages="$errors->get($methodModel)" class="mt-1" />
    <x-input-error :messages="$errors->get($bankModel)" class="mt-1" />
</div>
