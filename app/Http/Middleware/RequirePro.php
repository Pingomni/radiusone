<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\License;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "pro:billing": a RadiusOne Pro feature. Pages open while a licence lets the feature run
 * (also once it has expired); changes need an active licence. Without one, a page explains what Pro adds.
 */
final class RequirePro
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $read = $request->isMethodSafe();
        if (License::allows($feature, write: !$read)) {
            return $next($request);
        }
        $name = License::FEATURES[$feature];
        $expired = License::current()['state'] === 'expired' && License::allows($feature);
        $message = $expired
            ? "The RadiusOne Pro licence has expired: {$name} keeps running as it is, and can be changed again once the licence is renewed."
            : "{$name} is part of RadiusOne Pro. Add a licence key under Settings → License to turn it on.";
        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json(['error' => 'pro', 'message' => $message], 403);
        }
        if (!$read) {
            return back()->with('flash', ['type' => 'warning', 'message' => $message]);
        }

        return Inertia::render('Pro', ['feature' => $feature, 'name' => $name, 'message' => $message])->toResponse($request)->setStatusCode(403);
    }
}
