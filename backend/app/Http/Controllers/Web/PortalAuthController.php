<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PortalAuth;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Session (web guard) sign-in for the student and guardian portal. Credentials go through
 * AuthService::authenticate(), the same lookup, throttles and inactive-account rule as
 * `POST /api/login`, but no API token is issued. Only the student and parent roles get a
 * session; a staff user is sent to the admin sign-in instead (see login()).
 */
class PortalAuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function show(Request $request)
    {
        $user = Auth::guard('web')->user();

        if ($user?->hasAnyRole(PortalAuth::ROLES)) {
            return redirect()->route('portal.dashboard');
        }

        return view('portal.login');
    }

    /**
     * A wrong password, an unknown login and an inactive account all fail in AuthService as
     * a ValidationException on `login`; the form shows one generic bilingual message for
     * them. A staff user (admin, teacher, office) who signs in here with correct
     * credentials is redirected to /admin/login without a session: staff use the admin SPA,
     * and the portal never opens for them.
     */
    public function login(LoginRequest $request)
    {
        $user = $this->auth->authenticate(
            $request->validated('login'),
            $request->validated('password'),
            (string) $request->ip(),
        );

        if (! $user->hasAnyRole(PortalAuth::ROLES)) {
            return redirect('/admin/login?from=portal');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('portal.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
