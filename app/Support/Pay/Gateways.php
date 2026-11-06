<?php

declare(strict_types=1);

namespace App\Support\Pay;

use App\Support\Settings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The card services the pay page can use: POK (pokpay.io) and Stripe (Checkout). Each ISP uses its own account, and the
 * customer pays on the service's own page. A status here is always read from the service, never taken from a request.
 *
 * @phpstan-type Status array{state: 'completed'|'canceled'|'waiting', amount: float, currency: string, reference: string, note: string}
 */
final class Gateways
{
    public const NAMES = ['pok' => 'POK', 'stripe' => 'Stripe'];

    /**
     * Each service has test and live credentials, saved apart, and a switch for which one the pay page uses now. A
     * payment keeps the mode it was made in (pay_payments.mode), so it is always checked with the same credentials.
     */
    public const MODES = ['test' => 'Test', 'live' => 'Live'];

    /** The credentials each service needs, per mode: {service}_{mode}_{field} in settings; the last one is secret. */
    public const FIELDS = ['pok' => ['merchant_id', 'key_id', 'key_secret'], 'stripe' => ['secret_key']];

    /** Stripe's currencies without decimals: an amount is sent as it is, not in hundredths. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Stripe's currencies with three decimals (and others it treats specially) are not handled here. */
    private const UNSUPPORTED = ['BHD', 'JOD', 'KWD', 'OMR', 'TND', 'ISK', 'HUF', 'TWD'];

    /** @return list<string> the services turned on and filled in, the ones a customer can choose */
    public static function available(): array
    {
        return array_values(array_filter(array_keys(self::NAMES), static fn (string $name) => self::ready($name)));
    }

    /** The mode the pay page uses now for this service: test until switched to live. */
    /** How a payment's service reads in lists: "POK", or "POK (test)" for a test payment. */
    public static function label(string $name, string $mode): string
    {
        return (self::NAMES[$name] ?? $name) . ($mode === 'live' ? '' : ' (test)');
    }

    public static function mode(string $name): string
    {
        return Settings::value($name . '_mode', 'test') === 'live' ? 'live' : 'test';
    }

    public static function credential(string $name, string $mode, string $field): string
    {
        return Settings::value("{$name}_{$mode}_{$field}", '');
    }

    public static function configured(string $name, ?string $mode = null): bool
    {
        $mode ??= self::mode($name);
        foreach (self::FIELDS[$name] ?? [''] as $field) {
            if ($field === '' || self::credential($name, $mode, $field) === '') {
                return false;
            }
        }
        return true;
    }

    public static function ready(string $name): bool
    {
        return Settings::value($name . '_enabled', '0') === '1' && self::configured($name);
    }

    /**
     * A payment page at the service.
     *
     * @param  array{token: string, reference: string, amount: float, currency: string, description: string, return_url: string, webhook_url: string}  $order
     * @return array{id: string, url: string, expires: string}
     */
    public static function create(string $name, string $mode, array $order): array
    {
        return match ($name) {
            'pok' => self::pokCreate($mode, $order),
            'stripe' => self::stripeCreate($mode, $order),
            default => throw new RuntimeException('Unknown payment service.'),
        };
    }

    /** @return Status */
    public static function status(string $name, string $mode, string $id): array
    {
        return match ($name) {
            'pok' => self::pokStatus($mode, $id),
            'stripe' => self::stripeStatus($mode, $id),
            default => throw new RuntimeException('Unknown payment service.'),
        };
    }

    /** Checks one mode's saved credentials with the service. No order is made. */
    public static function test(string $name, string $mode): void
    {
        if (!self::configured($name, $mode)) {
            throw new RuntimeException(self::NAMES[$name] . ': no ' . strtolower(self::MODES[$mode] ?? $mode) . ' credentials are saved.');
        }
        match ($name) {
            'pok' => self::pokToken($mode),
            'stripe' => self::stripeCall($mode, 'GET', '/checkout/sessions', ['limit' => 1]),
            default => throw new RuntimeException('Unknown payment service.'),
        };
    }

    // POK

    /**
     * @param  array{token: string, reference: string, amount: float, currency: string, description: string, return_url: string, webhook_url: string}  $order
     * @return array{id: string, url: string, expires: string}
     */
    private static function pokCreate(string $mode, array $order): array
    {
        $data = self::pokCall($mode, 'POST', '/merchants/' . rawurlencode(self::credential('pok', $mode, 'merchant_id')) . '/sdk-orders', [
            'amount' => round($order['amount'], 2),
            'currencyCode' => $order['currency'],
            'autoCapture' => true,
            'shippingCost' => 0,
            'description' => mb_substr($order['description'], 0, 200),
            'merchantCustomReference' => $order['reference'],
            'redirectUrl' => $order['return_url'],
            'failRedirectUrl' => $order['return_url'],
            'webhookUrl' => $order['webhook_url'],
            'expiresAfterMinutes' => 60,
        ]);
        $pok = (array) ($data['sdkOrder'] ?? []);
        $url = $pok['_self']['confirmUrl'] ?? null;
        if (empty($pok['id']) || !is_string($url) || !str_starts_with($url, 'https://')) {
            throw new RuntimeException('POK did not return a payment page for the order.');
        }
        return ['id' => (string) $pok['id'], 'url' => $url, 'expires' => date('Y-m-d H:i:s', isset($pok['expiresAt']) ? (int) strtotime((string) $pok['expiresAt']) : time() + 3600)];
    }

    /** @return Status */
    private static function pokStatus(string $mode, string $id): array
    {
        $pok = (array) (self::pokCall($mode, 'GET', '/merchants/' . rawurlencode(self::credential('pok', $mode, 'merchant_id')) . '/sdk-orders/' . rawurlencode($id))['sdkOrder'] ?? []);
        if (($pok['id'] ?? null) !== $id) {
            throw new RuntimeException('POK returned another order.');
        }
        $state = !empty($pok['isCanceled']) || !empty($pok['isRefunded']) ? 'canceled' : (empty($pok['isCompleted']) ? 'waiting' : 'completed');
        return [
            'state' => $state,
            'amount' => (float) ($pok['originalAmount'] ?? $pok['finalAmount'] ?? $pok['amount'] ?? 0),
            'currency' => (string) ($pok['originalCurrencyCode'] ?? $pok['currencyCode'] ?? ''),
            'reference' => (string) ($pok['transactionId'] ?? $pok['id']),
            'note' => !empty($pok['isRefunded']) ? 'Refunded at POK.' : (!empty($pok['isCanceled']) ? 'Canceled at POK.' : ''),
        ];
    }

    /** Test payments go to POK's staging service, live ones to production. */
    private static function pokBase(string $mode): string
    {
        return $mode === 'live' ? 'https://api.pokpay.io' : 'https://api-staging.pokpay.io';
    }

    private static function pokToken(string $mode): string
    {
        $response = Http::timeout(15)->acceptJson()->post(self::pokBase($mode) . '/auth/sdk/login', ['keyId' => self::credential('pok', $mode, 'key_id'), 'keySecret' => self::credential('pok', $mode, 'key_secret')]);
        $token = $response->json('data.accessToken');
        if (!$response->successful() || !is_string($token) || $token === '') {
            $where = $mode === 'live' ? 'production' : 'staging';
            throw new RuntimeException("POK {$where} refused the API key (HTTP {$response->status()}): check the {$mode} key id and secret.");
        }
        return $token;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function pokCall(string $mode, string $method, string $path, array $body = []): array
    {
        /** @var Response $response */
        $response = Http::timeout(20)->acceptJson()->withToken(self::pokToken($mode))->send($method, self::pokBase($mode) . $path, $body === [] ? [] : ['json' => $body]);
        if (!$response->successful()) {
            throw new RuntimeException("POK answered HTTP {$response->status()} to {$method} {$path}.");
        }
        return (array) $response->json('data', []);
    }

    // Stripe

    /**
     * @param  array{token: string, reference: string, amount: float, currency: string, description: string, return_url: string, webhook_url: string}  $order
     * @return array{id: string, url: string, expires: string}
     */
    private static function stripeCreate(string $mode, array $order): array
    {
        $expires = time() + 3600;
        $session = self::stripeCall($mode, 'POST', '/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $order['return_url'],
            'cancel_url' => $order['return_url'],
            'client_reference_id' => $order['reference'],
            'expires_at' => $expires,
            'metadata' => ['reference' => $order['reference']],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($order['currency']),
                    'unit_amount' => self::stripeMinor($order['amount'], $order['currency']),
                    'product_data' => ['name' => mb_substr($order['description'], 0, 200)],
                ],
            ]],
        ], $order['token']);
        $url = $session['url'] ?? null;
        if (empty($session['id']) || !is_string($url) || !str_starts_with($url, 'https://')) {
            throw new RuntimeException('Stripe did not return a payment page for the order.');
        }
        return ['id' => (string) $session['id'], 'url' => $url, 'expires' => date('Y-m-d H:i:s', isset($session['expires_at']) ? (int) $session['expires_at'] : $expires)];
    }

    /** @return Status */
    private static function stripeStatus(string $mode, string $id): array
    {
        $session = self::stripeCall($mode, 'GET', '/checkout/sessions/' . rawurlencode($id));
        if (($session['id'] ?? null) !== $id) {
            throw new RuntimeException('Stripe returned another session.');
        }
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        // A session can be complete and still unpaid while a slower payment method settles.
        $paid = ($session['status'] ?? '') === 'complete' && ($session['payment_status'] ?? '') === 'paid';
        return [
            'state' => $paid ? 'completed' : (($session['status'] ?? '') === 'expired' ? 'canceled' : 'waiting'),
            'amount' => self::stripeMajor((int) ($session['amount_total'] ?? 0), $currency),
            'currency' => $currency,
            'reference' => (string) ($session['payment_intent'] ?? $session['id']),
            'note' => ($session['status'] ?? '') === 'expired' ? 'Stripe\'s payment page expired without a payment.' : '',
        ];
    }

    private static function stripeMinor(float $amount, string $currency): int
    {
        $code = strtoupper($currency);
        if (in_array($code, self::UNSUPPORTED, true)) {
            throw new RuntimeException("The pay page does not take {$code} through Stripe.");
        }
        return in_array($code, self::ZERO_DECIMAL, true) ? (int) round($amount) : (int) round($amount * 100);
    }

    private static function stripeMajor(int $amount, string $currency): float
    {
        return in_array($currency, self::ZERO_DECIMAL, true) ? (float) $amount : $amount / 100;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function stripeCall(string $mode, string $method, string $path, array $body = [], ?string $idempotency = null): array
    {
        $request = Http::timeout(20)->acceptJson()->withToken(self::credential('stripe', $mode, 'secret_key'))->asForm();
        if ($idempotency !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotency]);
        }
        /** @var Response $response */
        $response = $method === 'GET' ? $request->get('https://api.stripe.com/v1' . $path, $body) : $request->post('https://api.stripe.com/v1' . $path, $body);
        if (!$response->successful()) {
            $why = $response->status() === 401 ? ': check the secret key' : ': ' . mb_substr((string) $response->json('error.message', ''), 0, 200);
            throw new RuntimeException("Stripe answered HTTP {$response->status()}{$why}.");
        }
        return (array) $response->json();
    }
}
