<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Diagnostics;
use App\Support\SchemaMismatch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxy addresses come from config/trustedproxy.php (TRUSTED_PROXIES), read per request.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->encryptCookies(except: ['frweb_theme']);
        // After TrustProxies (a default global middleware), so X-Forwarded-Proto is honoured.
        $middleware->append([SecurityHeaders::class, ForceHttps::class]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'frweb.auth' => Authenticate::class,
            'permission' => RequirePermission::class,
            'recent-auth' => RequireRecentAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $e instanceof SchemaMismatch ? 503 : $response->getStatusCode();
            if (!in_array($status, [403, 404, 419, 500, 503], true) || ($status === 500 && config('app.debug'))) {
                return $response;
            }

            $reference = $status === 500 ? Diagnostics::report($e) : null;
            $detail = $e instanceof HttpExceptionInterface ? $e->getMessage() : '';
            [$title, $message] = match (true) {
                $e instanceof SchemaMismatch => ['Deployment required', $e->getMessage()],
                $status === 403 => ['Access denied', $detail !== '' && $detail !== 'Forbidden' ? $detail
                    : 'Your role does not include this action. Ask a workspace administrator if you need access.'],
                $status === 404 => ['Not found', $detail !== '' && $detail !== 'Not Found' ? $detail : 'Page not found.'],
                $status === 419 || $e instanceof TokenMismatchException => ['Page expired', 'This form was open too long or you signed in again in another tab, so it was not submitted. Go back, reload the page and submit again.'],
                $status === 503 => ['Maintenance', 'Application maintenance. Please retry shortly.'],
                default => ['Application error', 'Something went wrong. Reference: ' . $reference . '.'],
            };

            $plain = !$request->header('X-Inertia') && !str_contains((string) $request->header('Accept'), 'text/html');
            if ($plain) {
                return response($message, $status, ['Content-Type' => 'text/plain; charset=utf-8'] + ($reference ? ['X-Request-ID' => $reference] : []));
            }

            $back = '/';
            $referer = (string) $request->headers->get('referer', '');
            if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === $request->getHost()) {
                $back = (string) (parse_url($referer, PHP_URL_PATH) ?: '/') . (($query = parse_url($referer, PHP_URL_QUERY)) ? '?' . $query : '');
            }

            $rendered = Inertia::render('Error', ['status' => $status, 'title' => $title, 'message' => $message, 'back' => $back])
                ->toResponse($request)
                ->setStatusCode($status);
            if ($reference) {
                $rendered->headers->set('X-Request-ID', $reference);
            }
            return $rendered;
        });
    })->create();

// Secrets live in the protected shared env file; see App\Support\SharedEnv.
$app->afterLoadingEnvironment(static fn () => App\Support\SharedEnv::load());

return $app;
