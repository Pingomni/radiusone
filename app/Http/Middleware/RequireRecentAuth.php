<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive actions need a password (+ code) confirmation within the last five minutes.
 * The operator returns to the form they came from (never an external Referer), without its inputs.
 */
final class RequireRecentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::recentlyAuthenticated()) {
            return $next($request);
        }

        $source = $request->isMethod('GET') ? $request->getRequestUri() : (string) $request->headers->get('referer', '');
        if ($source !== '' && !str_starts_with($source, '/')) {
            $parts = parse_url($source);
            $source = ($parts['host'] ?? null) === $request->getHost()
                ? ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '')
                : '';
        }
        $returnPath = Auth::internalReturnPath($source, '');
        if ($returnPath === '') {
            $path = '/' . ltrim($request->path(), '/');
            $id = is_scalar($request->input('id')) ? max(0, (int) $request->input('id')) : 0;
            $returnPath = match (true) {
                $path === '/nas/save' => $id ? '/nas/' . $id . '/edit' : '/nas/create',
                preg_match('#^/nas/\d+/delete$#', $path) === 1 => '/nas',
                $path === '/settings/users/save' => $id ? '/settings/users/' . $id . '/edit' : '/settings/users/create',
                str_starts_with($path, '/settings/users/') || str_starts_with($path, '/portal-users/') => '/settings?tab=users',
                $path === '/settings/notifications/sms-gateway' => '/settings?tab=notifications&view=gateways&gateway=sms',
                $path === '/settings/notifications/email-gateway' => '/settings?tab=notifications&view=gateways&gateway=email',
                preg_match('#^/clients/([^/]+)/reveal-password$#', $path, $match) === 1 => '/clients/' . $match[1] . '/edit',
                preg_match('#^/billing/invoices/(\d+)/void$#', $path, $match) === 1 => '/billing/invoices/' . $match[1],
                default => '/profile',
            };
        }
        Auth::rememberReauthReturn($returnPath);
        return redirect('/reauth');
    }
}
