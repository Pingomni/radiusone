<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Same headers as the legacy portal: strict CSP, no framing, no caching of operator pages. */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $headers->remove('X-Powered-By');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::csp());
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (!$headers->has('Cache-Control') || str_contains((string) $headers->get('Content-Type'), 'text/html') || $request->header('X-Inertia')) {
            $headers->set('Cache-Control', 'no-store, max-age=0');
        }
        return $response;
    }

    private static function csp(): string
    {
        $dev = app()->environment('local') && is_file(public_path('hot'));
        $vite = $dev ? ' ' . trim((string) file_get_contents(public_path('hot'))) : '';
        $connect = $dev ? $vite . ' ' . str_replace('http', 'ws', trim($vite)) : '';
        return "default-src 'self'; script-src 'self'{$vite}; style-src 'self'{$vite}; img-src 'self' data: blob: https:; font-src 'self'; "
            . "connect-src 'self'{$connect}; form-action 'self'; base-uri 'self'; frame-ancestors 'none'";
    }
}
