<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth;
use App\Support\Registration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web console only: once the grace is over and this server is not registered (Registration), every console page
 * and action leads to "Register this server". Signing in and out, the two-step sign-in, re-entering the password,
 * Settings → License (the tab, saving a key), the health check and the branding images still open, so a key can
 * always be added. RADIUS, the network queue and scheduled jobs never pass through here.
 */
final class RequireRegistration
{
    /** Paths that open while the console is locked (Request::is patterns). */
    public const EXEMPT = [
        'login', 'logout', 'two-factor', 'two-factor/setup', 'reauth', 'register',
        'settings/license', 'up', 'uploads/branding/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Visitors who are not signed in are sent to sign in first (Authenticate), then here.
        if (Auth::user() === null || $this->exempt($request) || !Registration::locked()) {
            return $next($request);
        }
        $message = 'This server is not registered. Get a free RadiusOne licence at account.pingomni.com and add it under Settings → License.';
        if (($request->expectsJson() && !$request->header('X-Inertia')) || $request->header('X-Live-Fragment')) {
            return response()->json(['error' => 'registration', 'message' => $message], 403);
        }

        // 303: a form post is followed with a GET of the registration page.
        return redirect('/register', 303);
    }

    private function exempt(Request $request): bool
    {
        if ($request->is(...self::EXEMPT)) {
            return true;
        }

        // Settings → License itself, and nothing else on the Settings page.
        return $request->is('settings') && $request->isMethodSafe() && $request->query('tab') === 'license';
    }
}
