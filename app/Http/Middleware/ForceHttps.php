<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Redirects plain-HTTP GETs to the canonical APP_URL; rejects other plain-HTTP methods. */
final class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isSecure() || !config('app.force_https')) {
            return $next($request);
        }
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response('Use HTTPS for this request.', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $canonical = rtrim((string) config('app.url'), '/');
        $parts = parse_url($canonical);
        if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return response('Canonical HTTPS URL is not configured.', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return redirect()->away($canonical . $request->getRequestUri(), 301);
    }
}
