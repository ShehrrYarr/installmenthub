<?php

use App\Http\Controllers\CollectionBookPrintController;
use App\Http\Controllers\CustomerReceiptController;
use App\Http\Controllers\ReceiptProofController;
use App\Http\Controllers\ThermalReceiptController;
use App\Models\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/', 'landing')->name('landing');

Route::get('dashboard', function () {
    $user = auth()->user();

    if ($user->hasRole('Super Admin')) {
        return redirect()->route('superadmin.dashboard');
    }

    abort_if(! $user->shop, 403, 'Your account is not assigned to a shop.');

    return redirect()->route('tenant.dashboard', $user->shop);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('receipts/{payment}/thermal', ThermalReceiptController::class)
    ->middleware(['auth'])
    ->name('receipts.thermal');

// Super Admin — global routes, bypass ShopScope entirely, manage tenants/billing.
Route::middleware(['auth', 'role:Super Admin'])
    ->prefix('super-admin')
    ->name('superadmin.')
    ->group(function () {
        Volt::route('/', 'dashboard.super-admin')->name('dashboard');

        Volt::route('shops', 'superadmin.shops.index')->name('shops.index');
        Volt::route('shops/create', 'superadmin.shops.form')->name('shops.create');
        Volt::route('shops/{shop}/edit', 'superadmin.shops.form')->name('shops.edit');
        Volt::route('shops/{shop}/staff', 'superadmin.shops.staff')->name('shops.staff');

        Volt::route('developer-tools', 'superadmin.developer-tools')->name('developer-tools');
    });

// Tenant — each shop gets its own URL (/s/{shop-slug}/...). ResolveTenant
// binds {shop} by slug, checks the user belongs to it (or is Super Admin),
// and sets the tenant context that ShopScope/BelongsToShop read from.
Route::middleware(['auth', 'tenant.resolve'])
    ->prefix('s/{shop}')
    ->name('tenant.')
    ->group(function () {
        Route::get('/', function () {
            return auth()->user()->hasAnyRole(['Shop Admin', 'Manager', 'Super Admin'])
                ? redirect()->route('tenant.dashboard.shop-admin')
                : redirect()->route('tenant.dashboard.salesman');
        })->name('dashboard');

        Volt::route('dashboard/shop-admin', 'dashboard.shop-admin')->name('dashboard.shop-admin');
        Volt::route('dashboard/salesman', 'dashboard.salesman')->name('dashboard.salesman');

        Volt::route('vendors', 'vendors.index')->name('vendors.index');
        Volt::route('vendors/create', 'vendors.form')->name('vendors.create');
        Volt::route('vendors/{vendor}/edit', 'vendors.form')->name('vendors.edit');
        Volt::route('vendors/{vendor}/ledger', 'vendors.ledger')->name('vendors.ledger');

        Volt::route('products', 'products.index')->name('products.index');
        Volt::route('products/create', 'products.form')->name('products.create');
        Volt::route('products/{product}/edit', 'products.form')->name('products.edit');
        Volt::route('products/{product}/items', 'products.items')->name('products.items');

        Volt::route('purchase-orders', 'purchase-orders.index')->name('purchase-orders.index');
        Volt::route('purchase-orders/create', 'purchase-orders.form')->name('purchase-orders.create');
        Volt::route('purchase-orders/{purchaseOrder}/receive', 'purchase-orders.receive')->name('purchase-orders.receive');

        Volt::route('expenses', 'expenses.index')->name('expenses.index');
        Volt::route('expenses/create', 'expenses.form')->name('expenses.create');
        Volt::route('expenses/{expense}/edit', 'expenses.form')->name('expenses.edit');

        Volt::route('customers', 'customers.index')->name('customers.index');
        Volt::route('customers/create', 'customers.form')->name('customers.create');
        Volt::route('customers/{customer}/edit', 'customers.form')->name('customers.edit');
        Volt::route('customers/{customer}/ledger', 'customers.ledger')->name('customers.ledger');

        Volt::route('agreements', 'agreements.index')->name('agreements.index');
        Volt::route('agreements/create', 'agreements.create')->name('agreements.create');
        Volt::route('agreements/{agreement}', 'agreements.show')->name('agreements.show');

        Volt::route('emi-calculator', 'agreements.emi-calculator')->name('emi-calculator');

        Volt::route('collections', 'collections.desk')->name('collections.desk');
        Volt::route('collections/book', 'collections.book')->name('collections.book');
        Route::get('collections/book/print', CollectionBookPrintController::class)->name('collections.book.print');

        Volt::route('profile', 'settings.profile')->name('profile');

        Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
        Volt::route('settings/staff', 'settings.staff')->name('settings.staff');
        Volt::route('settings/banks', 'settings.banks')->name('settings.banks');
        Volt::route('settings/emi', 'settings.emi')->name('settings.emi');

        // Bank slips live on the private disk; the controller decides who may
        // look at each one.
        Route::get('receipt-proofs/{media}', ReceiptProofController::class)->name('receipt-proof');
    });

// Customer portal — /{shop}/customer/*. Read-only views of a customer's own
// agreements, schedule, statement and receipts, on the `customer` guard.
Route::middleware(['customer.tenant'])
    ->prefix('{shop}/customer')
    ->name('customer.')
    ->group(function () {
        Volt::route('login', 'customer.login')->middleware('guest:customer')->name('login');

        Route::middleware('auth:customer')->group(function () {
            Route::post('logout', function (Shop $shop) {
                Auth::guard('customer')->logout();
                session()->invalidate();
                session()->regenerateToken();

                return redirect()->route('customer.login', ['shop' => $shop]);
            })->name('logout');

            Volt::route('/', 'customer.agreements')->name('agreements');
            Volt::route('agreements/{agreement}', 'customer.agreement')->name('agreement');
            Volt::route('ledger', 'customer.ledger')->name('ledger');
            Volt::route('password', 'customer.password')->name('password');
            Route::get('receipts/{payment}', CustomerReceiptController::class)->name('receipt');
            Route::get('receipt-proofs/{media}', ReceiptProofController::class)->name('receipt-proof');
        });
    });

require __DIR__.'/auth.php';

// Catch-all for a bare shop URL (e.g. /united-electronics, no /s/ prefix and
// no /login suffix) — registered last so every real static route above
// (dashboard, profile, confirm-password, etc.) still wins first. {shop}
// resolves via the global Route::bind in AppServiceProvider and 404s on its
// own if the slug doesn't exist, same as every other {shop} route.
Route::get('{shop}', function (Shop $shop) {
    $user = auth()->user();

    if ($user && ($user->hasRole('Super Admin') || $user->shop_id === $shop->id)) {
        return redirect()->route('tenant.dashboard', $shop);
    }

    return redirect()->route('login', ['shop' => $shop]);
})->name('shop.entry');
