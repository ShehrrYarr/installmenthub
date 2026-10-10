<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POS is an add-on a Super Admin switches on per shop. For a shop without it
 * the routes simply don't exist, so a 404 rather than a 403 — there is
 * nothing to be refused access to.
 *
 * This only covers full page loads; PosCheckout re-checks the switch for
 * Livewire actions, which go through the shared /livewire/update endpoint.
 */
class EnsurePosEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Tenant::current()?->posEnabled(), 404);

        return $next($request);
    }
}
