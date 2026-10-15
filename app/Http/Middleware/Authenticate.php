<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Requires a signed-in operator whose session version is current. */
final class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::user() === null) {
            return $request->expectsJson() || $request->header('X-Live-Fragment')
                ? response('Unauthenticated.', 401)
                : redirect('/login');
        }
        return $next($request);
    }
}
