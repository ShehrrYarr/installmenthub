@php
    $shop = \App\Support\Tenant::current();
    $customer = auth('customer')->user();

    $items = [
        ['route' => 'customer.agreements', 'label' => 'Agreements', 'icon' => 'document-text'],
        ['route' => 'customer.ledger', 'label' => 'Statement', 'icon' => 'book-open'],
        ['route' => 'customer.password', 'label' => 'Password', 'icon' => 'shield-check'],
    ];

    $link = fn (string $route) => route($route, ['shop' => $shop]);
    // The agreement detail page lives under customer.agreement (singular), so
    // it keeps Agreements highlighted while you're reading one.
    $active = fn (string $route) => $route === 'customer.agreements'
        ? request()->routeIs('customer.agreements', 'customer.agreement')
        : request()->routeIs($route);
@endphp

{{-- Desktop sidebar --}}
<aside class="hidden lg:flex lg:flex-col lg:fixed lg:inset-y-0 border-r border-[var(--theme-sidebar-border)] bg-[var(--theme-sidebar)] backdrop-blur-xl transition-[width] duration-200"
       :class="sidebarCollapsed ? 'lg:w-20' : 'lg:w-64'">
    <div class="flex h-16 items-center gap-2 border-b border-[var(--theme-sidebar-border)] transition-[padding] duration-200"
         :class="sidebarCollapsed ? 'justify-center px-2' : 'px-6'">
        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--theme-accent)] text-[var(--theme-accent-text)] text-sm font-bold">
            {{ \Illuminate\Support\Str::of($shop?->name ?? 'IH')->substr(0, 2)->upper() }}
        </div>
        <span x-show="!sidebarCollapsed" x-cloak class="truncate font-semibold text-[var(--theme-sidebar-text)]">{{ $shop?->name }}</span>
        <button type="button" @click="sidebarCollapsed = !sidebarCollapsed" x-show="!sidebarCollapsed" x-cloak
                class="ml-auto shrink-0 rounded-lg p-1.5 text-[var(--theme-sidebar-text-muted)] hover:bg-[var(--theme-sidebar-hover-bg)]"
                title="Collapse sidebar">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 18l-6-6 6-6" />
            </svg>
        </button>
    </div>

    <button type="button" @click="sidebarCollapsed = !sidebarCollapsed" x-show="sidebarCollapsed" x-cloak
            class="mx-auto mt-2 shrink-0 rounded-lg p-1.5 text-[var(--theme-sidebar-text-muted)] hover:bg-[var(--theme-sidebar-hover-bg)]"
            title="Expand sidebar">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 18l6-6-6-6" />
        </svg>
    </button>

    <nav class="flex-1 space-y-1 px-3 py-4">
        @foreach ($items as $item)
            <a href="{{ $link($item['route']) }}" wire:navigate :title="sidebarCollapsed ? '{{ $item['label'] }}' : ''"
               class="flex items-center rounded-xl py-2.5 text-sm font-medium transition
                      {{ $active($item['route'])
                            ? 'bg-[var(--theme-sidebar-active-bg)] text-[var(--theme-sidebar-active-text)]'
                            : 'text-[var(--theme-sidebar-text-muted)] hover:bg-[var(--theme-sidebar-hover-bg)]' }}"
               :class="sidebarCollapsed ? 'justify-center px-2' : 'gap-3 px-3'">
                <x-tenant-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                <span x-show="!sidebarCollapsed" x-cloak>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="border-t border-[var(--theme-sidebar-border)] p-3">
        <div x-show="!sidebarCollapsed" x-cloak class="mb-2 px-2">
            <p class="truncate text-sm font-medium text-[var(--theme-sidebar-text)]">{{ $customer->full_name }}</p>
            <p class="truncate text-xs text-[var(--theme-sidebar-text-muted)]">{{ $customer->phone }}</p>
        </div>
        <form method="POST" action="{{ route('customer.logout', ['shop' => $shop]) }}">
            @csrf
            <button type="submit"
                    class="flex w-full items-center rounded-xl py-2.5 text-sm font-medium text-[var(--theme-sidebar-text-muted)] transition hover:bg-[var(--theme-sidebar-hover-bg)]"
                    :class="sidebarCollapsed ? 'justify-center px-2' : 'gap-3 px-3'"
                    :title="sidebarCollapsed ? 'Log Out' : ''">
                <x-tenant-icon name="arrow-left" class="h-5 w-5 shrink-0" />
                <span x-show="!sidebarCollapsed" x-cloak>Log Out</span>
            </button>
        </form>
    </div>
</aside>

{{-- Mobile bottom bar — same treatment as the staff panel's, including the
     shadow that lifts it off the page. Only four items, so unlike the staff
     bar it never needs to scroll. --}}
<nav class="lg:hidden fixed inset-x-0 bottom-0 z-40 bg-white/90 backdrop-blur-xl pb-[env(safe-area-inset-bottom)] shadow-[0_-10px_30px_-6px_rgba(0,0,0,0.25)]">
    <div class="grid grid-cols-4 gap-1 px-1 py-2">
        @foreach ($items as $item)
            <a href="{{ $link($item['route']) }}" wire:navigate
               class="flex flex-col items-center gap-0.5 rounded-lg py-1.5 text-[11px] font-medium
                      {{ $active($item['route']) ? 'text-[var(--theme-accent)]' : 'text-gray-500' }}">
                <x-tenant-icon :name="$item['icon']" class="h-5 w-5" />
                <span class="whitespace-nowrap">{{ $item['label'] }}</span>
            </a>
        @endforeach

        <form method="POST" action="{{ route('customer.logout', ['shop' => $shop]) }}">
            @csrf
            <button type="submit" class="flex w-full flex-col items-center gap-0.5 rounded-lg py-1.5 text-[11px] font-medium text-gray-500">
                <x-tenant-icon name="arrow-left" class="h-5 w-5" />
                <span class="whitespace-nowrap">Log Out</span>
            </button>
        </form>
    </div>
</nav>
