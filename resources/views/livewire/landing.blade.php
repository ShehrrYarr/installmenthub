<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.marketing')] class extends Component
{
    /** @var array<int, array{icon: string, title: string, description: string}> */
    public array $features = [
        ['icon' => 'shield-check', 'title' => 'Multi-Tenant Shop Isolation', 'description' => "Every shop's data is automatically scoped — vendors, customers, and agreements never leak across shops, even for staff logged into different shops at once."],
        ['icon' => 'calculator', 'title' => 'Instant EMI Calculator', 'description' => 'Live down-payment, interest, and monthly-installment preview with a full schedule breakdown — before an agreement is ever created.'],
        ['icon' => 'truck', 'title' => 'Vendors & Purchase Orders', 'description' => 'Track suppliers and bank details, receive stock against purchase orders, and get automatic double-entry vendor ledgers.'],
        ['icon' => 'cube', 'title' => 'Serial / IMEI Tracking', 'description' => 'Every serialized unit — phones, laptops, ACs, fridges, inverters — gets a unique serial number registered at stock receiving.'],
        ['icon' => 'users', 'title' => 'Customer KYC Onboarding', 'description' => 'Full profile capture with income, employment, and home-ownership details, plus up to 5 verified documents per customer.'],
        ['icon' => 'document-text', 'title' => 'Agreement Lifecycle', 'description' => 'Draft → Pending Approval → Active → Completed / Defaulted, with two guarantors required and recorded on every agreement.'],
        ['icon' => 'banknotes', 'title' => 'Collection Desk', 'description' => 'Search by agreement number, CNIC, phone, or serial number, then post a payment against the right installment in seconds.'],
        ['icon' => 'printer', 'title' => 'Thermal Receipts & WhatsApp', 'description' => '80mm receipt printing for the counter, plus a one-tap WhatsApp message with the receipt for the customer.'],
        ['icon' => 'exclamation-triangle', 'title' => 'Automated Penalties', 'description' => "A daily sweep flags overdue installments past each shop's grace period and calculates late fees automatically."],
        ['icon' => 'book-open', 'title' => 'Customer & Vendor Ledgers', 'description' => 'Full running-balance statements — down payments, interest charged, collections, and penalties levied, in one place.'],
        ['icon' => 'sparkles', 'title' => 'Role-Based Dashboards', 'description' => 'Purpose-built views for Shop Admin, Manager, and Salesman — each sees exactly what their role needs.'],
        ['icon' => 'device-phone-mobile', 'title' => 'Mobile-First POS', 'description' => 'Bottom navigation and touch-friendly forms, built for a salesman working the floor on a phone, not a desk.'],
    ];

    /** @var array<int, array{role: string, label: string, description: string, badge: string}> */
    public array $demoRoles = [
        ['role' => 'shop-admin', 'label' => 'Shop Admin', 'badge' => 'Karachi Electronics Hub', 'description' => 'Full control of one shop — vendors, products, purchase orders, agreements, and the collection desk.'],
        ['role' => 'manager', 'label' => 'Manager', 'badge' => 'Karachi Electronics Hub', 'description' => 'Day-to-day shop operations — everything Shop Admin sees, without the shop\'s own billing controls.'],
        ['role' => 'salesman', 'label' => 'Salesman', 'badge' => 'Karachi Electronics Hub', 'description' => 'The mobile-first view — today\'s collection round, quick agreement creation, and the collection desk.'],
    ];

    public function enterAsDemo(string $role): void
    {
        abort_unless(config('app.demo_mode'), 404);

        $email = match ($role) {
            'shop-admin' => 'admin1@installmenthub.test',
            'manager' => 'manager1@installmenthub.test',
            'salesman' => 'salesman1@installmenthub.test',
            default => null,
        };

        $user = $email ? User::where('email', $email)->first() : null;

        abort_unless($user, 404);

        Auth::login($user);
        session(['is_demo_session' => true]);

        $this->redirect(route('dashboard'), navigate: false);
    }
} ?>

<div class="min-h-screen bg-walnut-50">
    {{-- Nav — solid brand-amber band --}}
    <header class="sticky top-0 z-30 bg-walnut-600 shadow-md shadow-walnut-900/10">
        <div class="max-w-7xl mx-auto flex items-center justify-between px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center gap-2">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-walnut-900 text-white font-bold text-sm">IH</div>
                <span class="font-semibold text-white">{{ config('app.name') }}</span>
            </div>
            <div class="flex items-center gap-4">
                <a href="#demo" class="hidden sm:inline text-sm font-medium text-white/85 hover:text-white">Live Demo</a>
                <a href="#features" class="hidden sm:inline text-sm font-medium text-white/85 hover:text-white">Features</a>
                <a href="{{ route('superadmin.login') }}" class="rounded-lg bg-walnut-900 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-900/80">Admin Login</a>
            </div>
        </div>
    </header>

    {{-- Hero --}}
    <section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-12 text-center">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-walnut-200 px-3 py-1 text-xs font-medium text-walnut-900 ring-1 ring-inset ring-walnut-400/40">
            <span class="h-1.5 w-1.5 rounded-full bg-walnut-600"></span>
            Multi-tenant SaaS for electronics installment stores
        </span>
        <h1 class="mt-6 text-4xl sm:text-5xl font-bold tracking-tight text-walnut-900">
            Run every shop's installment business from one platform.
        </h1>
        <p class="mt-5 text-lg text-walnut-900/70 max-w-2xl mx-auto">
            Vendors, inventory with serial tracking, customer KYC, EMI agreements, collections, and automated penalties —
            each shop fully isolated, each role seeing exactly what it needs.
        </p>
        <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="#demo" class="w-full sm:w-auto rounded-lg bg-walnut-600 px-6 py-3 text-sm font-semibold text-white hover:bg-walnut-900 shadow-lg shadow-walnut-900/15">
                Try the Live Demo
            </a>
            <a href="#features" class="w-full sm:w-auto rounded-lg bg-white px-6 py-3 text-sm font-semibold text-walnut-900 ring-1 ring-inset ring-walnut-200 hover:bg-walnut-100">
                See Every Feature
            </a>
        </div>
    </section>

    {{-- Feature grid --}}
    <section id="features" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="text-center mb-12">
            <h2 class="text-2xl sm:text-3xl font-bold text-walnut-900">Everything a shop needs, built in</h2>
            <p class="mt-3 text-walnut-900/60">Twelve modules, working together end to end.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ($features as $feature)
                <div class="rounded-2xl border border-walnut-200 bg-white shadow-sm hover:shadow-md hover:-translate-y-0.5 transition p-6">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-walnut-400 text-white mb-4">
                        <x-tenant-icon :name="$feature['icon']" class="h-6 w-6" />
                    </div>
                    <h3 class="font-semibold text-walnut-900">{{ $feature['title'] }}</h3>
                    <p class="mt-1.5 text-sm text-walnut-900/60">{{ $feature['description'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Live demo --}}
    <section id="demo" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="text-center mb-4">
            <h2 class="text-2xl sm:text-3xl font-bold text-walnut-900">Try it as any role</h2>
            <p class="mt-3 text-walnut-900/60">One click, no signup — you're instantly logged into a real seeded account.</p>
        </div>

        @if (! config('app.demo_mode'))
            <div class="max-w-2xl mx-auto mt-8 rounded-2xl border border-walnut-400/40 bg-walnut-200/60 px-5 py-4 text-sm text-walnut-900 text-center">
                The live demo is currently disabled on this deployment.
            </div>
        @else
            <div class="max-w-2xl mx-auto mt-6 rounded-2xl border border-walnut-200 bg-white px-5 py-3 text-sm text-walnut-900/70 text-center">
                This is shared demo data — other visitors are using the same accounts right now, and changes may be reset at any time. Don't rely on anything you save here.
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mt-8">
            @foreach ($demoRoles as $demo)
                <div class="flex flex-col rounded-2xl border-t-4 border-walnut-400 border-x border-b border-x-walnut-200 border-b-walnut-200 bg-white shadow-sm hover:shadow-md transition p-6">
                    <span class="inline-flex self-start items-center rounded-full bg-walnut-900 px-2.5 py-1 text-[11px] font-medium text-walnut-200 mb-3">
                        {{ $demo['badge'] }}
                    </span>
                    <h3 class="font-semibold text-walnut-900">{{ $demo['label'] }}</h3>
                    <p class="mt-2 text-sm text-walnut-900/60 flex-1">{{ $demo['description'] }}</p>
                    <button
                        wire:click="enterAsDemo('{{ $demo['role'] }}')"
                        wire:loading.attr="disabled"
                        wire:target="enterAsDemo('{{ $demo['role'] }}')"
                        @disabled(! config('app.demo_mode'))
                        class="mt-5 w-full rounded-lg bg-walnut-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-walnut-900 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span wire:loading.remove wire:target="enterAsDemo('{{ $demo['role'] }}')">Enter as {{ $demo['label'] }}</span>
                        <span wire:loading wire:target="enterAsDemo('{{ $demo['role'] }}')">Signing in…</span>
                    </button>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Footer — solid brand-amber band --}}
    <footer class="bg-walnut-600 mt-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-sm text-white/85">
            <div class="flex items-center gap-2">
                <div class="flex h-6 w-6 items-center justify-center rounded-md bg-walnut-900 text-white font-bold text-[10px]">IH</div>
                <span class="text-white">{{ config('app.name') }}</span>
            </div>
            <p>Built with Laravel, Livewire &amp; Volt.</p>
        </div>
    </footer>
</div>
