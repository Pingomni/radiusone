<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PayPage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the public pay page and the operator portal apart. On the pay page's address only the pay page answers, so the
 * sign-in and every operator page are out of reach from there; on any other address the pay page does not exist.
 */
final class PayHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        $payPath = $path === 'pay' || str_starts_with($path, 'pay/');
        $onPayHost = PayPage::isPayHost($request->getHost());
        if (!$onPayHost) {
            abort_if($payPath, 404);
            return $next($request);
        }
        if (!PayPage::enabled()) {
            abort(404);
        }
        if ($path === '') {
            return redirect('/pay');
        }
        abort_unless($payPath || str_starts_with($path, 'uploads/branding/') || $path === 'up', 404);
        return $next($request);
    }
}
