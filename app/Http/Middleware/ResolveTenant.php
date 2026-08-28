<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /s/{shop}/* routes: the {shop} slug (see AppServiceProvider's
 * Route::bind) must belong to the authenticated user, unless they're a
 * Super Admin — in which case visiting a shop's URL is how they "step into"
 * it. That choice is remembered in the session (see App\Support\Tenant),
 * since it needs to survive into the separate /livewire/update requests
 * that never pass through this middleware.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->route('shop');
        $user = $request->user();

        if (! $shop instanceof Shop) {
            abort(404);
        }

        if ($user->hasRole('Super Admin')) {
            session(['superadmin_shop_id' => $shop->id]);
        } elseif ($user->shop_id !== $shop->id) {
            abort(403, 'You do not have access to this shop.');
        }

        if ($shop->subscription_status === 'suspended') {
            abort(403, 'This shop\'s subscription is suspended. Contact support to reactivate.');
        }

        Tenant::set($shop);
        URL::defaults(['shop' => $shop->slug]);

        return $next($request);
    }
}
