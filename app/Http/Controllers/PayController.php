<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Clients;
use App\Support\Db;
use App\Support\PayPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * The public pay page, reached only on its own address (App\Http\Middleware\PayHost). It shows what a username's
 * contract allows, and nothing a request says counts as a payment: only what the service answers does (PayPage::sync).
 */
final class PayController extends Controller
{
    private const SESSION = 'pay_username';

    public function page(Request $request): Response
    {
        $username = (string) $request->session()->get(self::SESSION, '');
        $account = $username !== '' ? PayPage::account($username) : null;
        return Inertia::render('pay/Pay', ['page' => PayPage::view(), 'account' => $account ? $this->account($account) : null, 'state' => null]);
    }

    /** Wrong phone digits allowed per username before it waits: 4 digits must not be guessable. */
    private const TRIES = 5;

    private const WAIT_SECONDS = 900;

    public function lookup(Request $request): RedirectResponse
    {
        $username = trim((string) $request->input('username', ''));
        $labels = PayPage::view()['labels'];
        $request->session()->forget(self::SESSION);
        $warn = static fn (string $message) => redirect('/pay')->with('flash', ['type' => 'warning', 'message' => $message]);
        $key = 'pay-lookup:' . hash('sha256', mb_strtolower($username));
        if (RateLimiter::tooManyAttempts($key, self::TRIES)) {
            return $warn($labels['locked']);
        }
        // The same answer for an unknown username, a wrong phone number and no active contract.
        $customer = PayPage::identify($username, (string) $request->input('phone', ''));
        if (!$customer) {
            RateLimiter::hit($key, self::WAIT_SECONDS);
            return $warn($labels['not_found']);
        }
        RateLimiter::clear($key);
        if ((int) $customer['online_pay'] !== 1) {
            return $warn($labels['no_online']);
        }
        if (!PayPage::account((string) $customer['username'])) {
            return $warn($labels['not_found']);
        }
        $request->session()->put(self::SESSION, (string) $customer['username']);
        return redirect('/pay');
    }

    public function forget(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);
        return redirect('/pay');
    }

    public function start(Request $request): HttpResponse
    {
        $username = (string) $request->session()->get(self::SESSION, '');
        $root = 'https://' . $request->getHost();
        try {
            $url = PayPage::start($username, (string) $request->input('period', ''), (string) $request->input('provider', ''), static fn (string $token) => "{$root}/pay/return/{$token}", "{$root}/pay/webhook");
        } catch (Throwable $e) {
            Log::warning('Pay page: could not start a payment: ' . $e->getMessage());
            return redirect('/pay')->with('flash', ['type' => 'danger', 'message' => PayPage::view()['labels']['unavailable']]);
        }
        // A full page visit to the service's own page.
        return Inertia::location($url);
    }

    /** Back from the service's page, paid or not: what the service says decides. The token in the address is the only key to this page. */
    public function back(string $token): Response
    {
        $payment = Db::row('SELECT * FROM pay_payments WHERE token=?', [$token]);
        abort_if(!$payment, 404);
        try {
            $state = PayPage::sync($payment);
        } catch (Throwable $e) {
            Log::warning('Pay page: could not check payment ' . $payment['id'] . ': ' . $e->getMessage());
            $state = 'waiting';
        }
        $fresh = Db::row('SELECT p.status, p.mode, i.invoice_number, c.expiry_date FROM pay_payments p JOIN customer_profiles c ON c.username=p.username LEFT JOIN billing_invoices i ON i.id=p.invoice_id WHERE p.id=?', [$payment['id']]);
        return Inertia::render('pay/Pay', ['page' => PayPage::view(), 'account' => null, 'state' => [
            'status' => $state,
            'test' => $fresh['mode'] !== 'live',
            'invoice' => $state === 'paid' ? $fresh['invoice_number'] : null,
            'until' => $state === 'paid' ? $fresh['expiry_date'] : null,
        ]]);
    }

    /** A service's notice that an order changed: only a reason to ask the service about it. */
    public function webhook(Request $request): JsonResponse
    {
        $id = collect([$request->input('data.sdkOrder.id'), $request->input('sdkOrder.id'), $request->input('sdkOrderId'), $request->input('data.object.id'), $request->input('data.id'), $request->input('id')])
            ->first(static fn ($v) => is_string($v) && preg_match('/^[A-Za-z0-9_-]{8,191}$/D', $v) === 1);
        $payment = $id ? Db::row('SELECT * FROM pay_payments WHERE provider_order_id=?', [$id]) : null;
        if ($payment) {
            try {
                PayPage::sync($payment);
            } catch (Throwable $e) {
                Log::warning('Pay page: webhook check failed for payment ' . $payment['id'] . ': ' . $e->getMessage());
            }
        }
        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    private function account(array $account): array
    {
        return [
            'username' => (string) $account['customer']['username'],
            'customer' => PayPage::maskName(Clients::displayName($account['customer'])),
            'service' => $account['service'],
            'paidUntil' => (string) ($account['customer']['expiry_date'] ?? ''),
            'contractUntil' => (string) $account['contract']['end_date'],
            'options' => array_map(static fn (array $o) => [
                'period' => $o['period'], 'months' => $o['months'], 'start' => $o['start'], 'end' => $o['end'],
                'amount' => number_format((float) $o['amount'], 2, '.', ''), 'currency' => $o['currency'],
            ], $account['options']),
        ];
    }
}
