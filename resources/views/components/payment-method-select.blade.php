@props([
    'methodModel',
    'bankModel',
    'label' => 'Payment Method',
    'withCredit' => false,
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
        </div>
    @endif

    <x-input-error :messages="$errors->get($methodModel)" class="mt-1" />
    <x-input-error :messages="$errors->get($bankModel)" class="mt-1" />
</div>
