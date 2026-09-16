<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.marketing')] class extends Component
{
    /** @var array<int, array{icon: string, title: string, description: string}> */
    public array $features = [
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

    /** wa.me deep link for the floating contact button — always visible, no demo-mode gate. */
    public function whatsappUrl(): string
    {
        return 'https://wa.me/923120883979?text='.rawurlencode('Hi! I am interested in the project');
    }

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

    {{-- Floating WhatsApp contact button --}}
    <a
        href="{{ $this->whatsappUrl() }}"
        target="_blank"
        rel="noopener noreferrer"
        aria-label="Chat with us on WhatsApp"
        class="fixed bottom-6 right-6 z-50 flex h-14 w-14 items-center justify-center"
    >
        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#25D366] opacity-75"></span>
        <span class="relative inline-flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] shadow-lg shadow-black/25 transition-colors hover:bg-[#1ebe5b]">
            <svg class="h-7 w-7 text-white" viewBox="0 0 448 512" fill="currentColor" aria-hidden="true">
                <path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z" />
            </svg>
        </span>
    </a>
</div>
