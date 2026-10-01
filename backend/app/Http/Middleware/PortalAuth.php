<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal's session (web guard) gate: a signed-in, active user with the student or
 * parent role. A guest is sent to the portal login; a signed-in staff user (who can't have
 * got in through the portal login, but may hold a web session from elsewhere) gets a 403.
 */
class PortalAuth
{
    public const ROLES = ['student', 'parent'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user === null) {
            return redirect()->route('portal.login');
        }

        if ($user->is_active === false) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login');
        }

        abort_unless($user->hasAnyRole(self::ROLES), 403);

        // The guard the controllers and services read the caller from.
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
