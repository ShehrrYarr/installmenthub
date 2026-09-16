<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff account can be deactivated (Super Admin's shop staff page,
 * Shop Admin's Settings → Staff) after the user already has a live session —
 * this catches that case on every request so a deactivated account is locked
 * out immediately, not just the next time they try to log in.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && ! $user->is_active) {
            // Resolve the login destination before logging out — it may depend
            // on the (about to be cleared) authenticated user's own shop. A
            // "Try the Live Demo" session (see landing.blade.php's
            // enterAsDemo) has no login of its own to return to, so it goes
            // back to the landing page instead, same as an explicit Log Out.
            $isDemo = (bool) $request->session()->get('is_demo_session');
            $loginUrl = $isDemo ? route('landing', ['demo_ended' => 1]) : Tenant::loginUrlFor($request);

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($isDemo) {
                return redirect()->to($loginUrl);
            }

            return redirect()->to($loginUrl)->withErrors([
                'form.email' => 'This account has been deactivated. Contact your shop admin.',
            ]);
        }

        return $next($request);
    }
}
