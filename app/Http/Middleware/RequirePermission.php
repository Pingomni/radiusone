<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware "permission:clients.manage" — 403 unless the role matrix grants it. */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        Auth::requireCan($permission);
        return $next($request);
    }
}
