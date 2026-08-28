<?php

namespace App\Support;

use App\Models\Shop;
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
            static::$current = static::resolve();
            static::$resolved = true;
        }

        return static::$current;
    }

    public static function id(): ?int
    {
        return static::current()?->id;
    }

    private static function resolve(): ?Shop
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        if ($user->hasRole('Super Admin')) {
            $shopId = session('superadmin_shop_id');

            return $shopId ? Shop::find($shopId) : null;
        }

        return $user->shop;
    }
}
