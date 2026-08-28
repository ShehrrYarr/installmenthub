@php
    $user = auth()->user();

    $link = fn (string $route) => \Illuminate\Support\Facades\Route::has($route) ? route($route) : '#';
    $active = fn (string $route) => \Illuminate\Support\Facades\Route::has($route) && request()->routeIs($route.'*');

    $shopThemeKey = \App\Support\Tenant::current()?->theme ?? \App\Support\ShopThemes::DEFAULT;
    $isFlatTheme = \App\Support\ShopThemes::style($shopThemeKey) === 'flat';

    // 'roles' gates by Spatie role (shared across every shop). 'permission'
    // gates by a permission granted directly on this user (see
    // App\Support\ShopPermissions) — used for the areas a Shop Admin can
    // toggle per-Manager/per-Salesman from Settings → Staff, since those
    // can't be pinned to a role without affecting every shop at once.
    // 'group' is only used by the 'flat' theme's grouped sidebar below —
    // the 3 glass themes render this same list without any grouping.
    $items = collect([
        ['route' => 'tenant.dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'roles' => null, 'permission' => null, 'group' => null],
        ['route' => 'tenant.customers.index', 'label' => 'Customers', 'icon' => 'users', 'roles' => null, 'permission' => null, 'group' => 'Sales'],
        ['route' => 'tenant.collections.desk', 'label' => 'Collect', 'icon' => 'banknotes', 'roles' => null, 'permission' => null, 'group' => 'Sales'],
        ['route' => 'tenant.collections.book', 'label' => 'Collection Book', 'icon' => 'book-open', 'roles' => null, 'permission' => null, 'group' => 'Sales'],
        ['route' => 'tenant.agreements.index', 'label' => 'Agreements', 'icon' => 'document-text', 'roles' => null, 'permission' => null, 'group' => 'Sales'],
        ['route' => 'tenant.products.index', 'label' => 'Products', 'icon' => 'cube', 'roles' => null, 'permission' => 'manage-products', 'group' => 'Inventory'],
        ['route' => 'tenant.vendors.index', 'label' => 'Vendors', 'icon' => 'truck', 'roles' => null, 'permission' => 'manage-vendors', 'group' => 'Inventory'],
        ['route' => 'tenant.purchase-orders.index', 'label' => 'Purchase Orders', 'icon' => 'document-text', 'roles' => null, 'permission' => 'manage-purchase-orders', 'group' => 'Inventory'],
        ['route' => 'tenant.settings.appearance', 'label' => 'Settings', 'icon' => 'cog', 'roles' => ['Shop Admin', 'Super Admin'], 'permission' => null, 'group' => 'Admin'],
    ])->filter(fn ($item) => (is_null($item['roles']) || $user->hasAnyRole($item['roles']))
        && (is_null($item['permission']) || $user->can($item['permission'])));

    $groupedItems = $items->whereNotNull('group')->groupBy('group');
    $ungroupedItems = $items->whereNull('group');
@endphp

{{-- Desktop sidebar --}}
<aside class="hidden lg:flex lg:flex-col lg:fixed lg:inset-y-0 border-r border-[var(--theme-sidebar-border)] bg-[var(--theme-sidebar)] backdrop-blur-xl transition-[width] duration-200"
       :class="sidebarCollapsed ? 'lg:w-20' : 'lg:w-64'">
    <div class="flex h-16 items-center gap-2 border-b border-[var(--theme-sidebar-border)] transition-[padding] duration-200"
         :class="sidebarCollapsed ? 'justify-center px-2' : 'px-6'">
        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--theme-accent)] text-[var(--theme-accent-text)] font-bold text-sm">IH</div>
        <span x-show="!sidebarCollapsed" x-cloak class="font-semibold text-[var(--theme-sidebar-text)] truncate">{{ \App\Support\Tenant::current()?->name ?? 'InstallmentHub' }}</span>
        <button type="button" @click="sidebarCollapsed = !sidebarCollapsed"
                x-show="!sidebarCollapsed" x-cloak
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
        @if ($isFlatTheme)
            @foreach ($ungroupedItems as $item)
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

            @foreach ($groupedItems as $group => $groupItems)
                <div class="pt-4 first:pt-0">
                    <p x-show="!sidebarCollapsed" x-cloak class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-[var(--theme-sidebar-text-muted)] opacity-70">
                        {{ $group }}
                    </p>
                    @foreach ($groupItems as $item)
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
                </div>
            @endforeach
        @else
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
        @endif
    </nav>

    <div class="border-t border-[var(--theme-sidebar-border)] p-3">
        <div x-show="!sidebarCollapsed" x-cloak>
            <livewire:layout.tenant-user-menu />
        </div>
        <div x-show="sidebarCollapsed" x-cloak class="flex justify-center">
            <livewire:layout.tenant-user-menu :compact="true" :open-upward="true" />
        </div>
    </div>
</aside>

{{-- Mobile bottom action bar --}}
<nav class="lg:hidden fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/90 backdrop-blur-xl pb-[env(safe-area-inset-bottom)]">
    <div class="grid grid-cols-5 gap-1 px-1 py-2">
        @foreach ($items->take(5) as $item)
            <a href="{{ $link($item['route']) }}" wire:navigate
               class="flex flex-col items-center gap-0.5 rounded-lg py-1.5 text-[11px] font-medium
                      {{ $active($item['route'])
                            ? 'text-[var(--theme-accent)]'
                            : 'text-gray-500' }}">
                <x-tenant-icon :name="$item['icon']" class="h-5 w-5" />
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
