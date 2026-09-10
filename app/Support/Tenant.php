<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The "current shop" for this request — read by ShopScope, BelongsToShop, and
 * anywhere else that needs tenant context.
 *
 * Livewire component actions (button clicks, form submits) execute as a
 * *separate* HTTP request to the shared /livewire/update endpoint, which
 * never passes through the /s/{shop}/* route group or its ResolveTenant
 * middleware — so tenant context can't just be "set once by middleware and
 * trusted for the rest of the request lifecycle" the way it could for a
 * traditional server-rendered app. Instead, current() lazily *resolves*
 * itself the first time it's asked, from whatever's reliably available on
 * any request: the authenticated user's own shop, or — for a Super Admin,
 * who has no shop of their own — the shop they last opened via a /s/{shop}/*
 * URL, tracked in the session (see ResolveTenant).
 */
class Tenant
{
    private static ?Shop $current = null;

    private static bool $resolved = false;

    /**
     * Explicitly pin the tenant for the rest of this request (called by
     * ResolveTenant once it has authoritatively matched the URL's {shop}).
     */
    public static function set(?Shop $shop): void
    {
        static::$current = $shop;
        static::$resolved = true;
    }

    public static function current(): ?Shop
    {
        if (! static::$resolved) {
            // Flagged *before* resolving, not after: resolving can query a
            // tenant-scoped model (the customer guard loads a Customer), and
            // ShopScope asks for the tenant on the way through. Marking it
            // resolved up front makes that nested call return null instead of
            // recursing forever.
            static::$resolved = true;
            static::$current = static::resolve();
        }

        return static::$current;
    }

    public static function id(): ?int
    {
        return static::current()?->id;
    }

    /**
     * Where to send a guest who needs to log in for the given request — the
     * shop's own login page when the request is under /{shop}/... or
     * /s/{shop}/... (there's no single shared /login anymore), the Super
     * Admin login for anything under /super-admin/..., or the landing page
     * as a last resort (e.g. a bare /dashboard hit with no shop in the URL).
     */
    public static function loginUrlFor(Request $request): string
    {
        // The URL's own {shop} first (authoritative for a real /{shop}/... or
        // /s/{shop}/... hit); falling back to the resolved tenant covers
        // requests with no {shop} route param of their own, like the shared
        // POST /livewire/update endpoint every Livewire action goes through.
        $shop = $request->route('shop');

        // Laravel's middleware priority runs Authenticate *before*
        // SubstituteBindings, so on the very request that triggers this the
        // {shop} parameter is usually still the raw slug — resolve it by hand
        // rather than falling through to the landing page.
        if (is_string($shop)) {
            $shop = Shop::where('slug', $shop)->first();
        }

        $shop = $shop instanceof Shop ? $shop : static::current();

        // The portal is a separate audience on its own guard — a customer
        // whose session lapsed belongs back on the customer login, not the
        // staff one.
        if ($shop instanceof Shop && str_starts_with((string) $request->route()?->getName(), 'customer.')) {
            return route('customer.login', ['shop' => $shop]);
        }

        if ($shop instanceof Shop) {
            return route('login', ['shop' => $shop]);
        }

        if (str_starts_with((string) $request->route()?->getName(), 'superadmin.')) {
            return route('superadmin.login');
        }

        return route('landing');
    }

    /** Portal home for the shop in this request's URL, for redirecting a signed-in customer. */
    public static function portalHomeFor(Request $request): string
    {
        $shop = $request->route('shop');

        if (is_string($shop)) {
            $shop = Shop::where('slug', $shop)->first();
        }

        return $shop instanceof Shop
            ? route('customer.agreements', ['shop' => $shop])
            : route('landing');
    }

    private static function resolve(): ?Shop
    {
        $user = Auth::user();

        if (! $user) {
            // Customer portal requests authenticate on their own guard. This
            // matters most on the shared /livewire/update endpoint, which
            // never passes through the portal's tenant middleware — without
            // it, portal actions would run with no tenant at all.
            return Auth::guard('customer')->user()?->shop;
        }

        if ($user->hasRole('Super Admin')) {
            $shopId = session('superadmin_shop_id');

            return $shopId ? Shop::find($shopId) : null;
        }

        return $user->shop;
    }
}
