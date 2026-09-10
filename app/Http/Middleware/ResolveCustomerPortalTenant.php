<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /{shop}/customer/* — the customer-facing portal.
 *
 * Deliberately separate from ResolveTenant, which resolves the tenant for
 * staff and would reject a request with no User. This sets the same tenant
 * context, which matters for more than convenience: with it in place,
 * ShopScope confines every customer lookup to this shop, so signing in and
 * session rehydration can never reach another shop's customer even if two
 * shops hold the same phone number.
 */
class ResolveCustomerPortalTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->route('shop');

        if (! $shop instanceof Shop) {
            abort(404);
        }

        if ($shop->subscription_status === 'suspended') {
            abort(403, 'This shop is not currently active. Please contact the shop.');
        }

        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);

        // Laravel's middleware priority resolves the guard before this runs,
        // so a session can already be authenticated against a *different*
        // shop's customer by the time we get here — ShopScope wasn't set yet
        // to prevent it. Send them to their own shop's portal rather than
        // rendering their data under someone else's branding.
        $customer = Auth::guard('customer')->user();

        if ($customer && $customer->shop_id !== $shop->id && $customer->shop) {
            return redirect()->route('customer.agreements', ['shop' => $customer->shop]);
        }

        return $next($request);
    }
}
