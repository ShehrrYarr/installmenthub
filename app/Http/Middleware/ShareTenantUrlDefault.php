<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered globally (see bootstrap/app.php) so route('tenant.*', ...) calls
 * work from *any* request — crucially including POST /livewire/update, which
 * every Livewire button click and form submit hits. That endpoint is shared
 * across all components and never passes through the /s/{shop}/* route
 * group, so ResolveTenant's URL::defaults() call never runs for it; this
 * fills the same gap using Tenant::current()'s own session/auth fallback.
 */
class ShareTenantUrlDefault
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($shop = Tenant::current()) {
            URL::defaults(['shop' => $shop->slug]);

            // A real GET /s/{shop}/... request is already checked by
            // ResolveTenant against the URL's own {shop} binding — leave
            // that alone. This only covers requests with no {shop} route
            // param (chiefly POST /livewire/update, which every wire:click
            // and wire:submit hits): without this, a shop suspended mid-
            // session couldn't stop an already-open tab from continuing to
            // post payments, approve agreements, etc. via Livewire actions,
            // since that endpoint never passes through ResolveTenant.
            if (! $request->route('shop') && $shop->subscription_status === 'suspended') {
                abort(403, "This shop's subscription is suspended. Contact support to reactivate.");
            }
        }

        return $next($request);
    }
}
