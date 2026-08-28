<?php

namespace App\Http\Middleware;

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
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'form.email' => 'This account has been deactivated. Contact your shop admin.',
            ]);
        }

        return $next($request);
    }
}
