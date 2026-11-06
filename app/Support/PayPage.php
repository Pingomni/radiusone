<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Pay\Gateways;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The public pay page (RadiusOne Pro), on its own address such as pay.example.com, set under Settings → Pay page: a
 * customer enters the username on their contract and the last 4 digits of their phone number, picks 1, 3, 6 or 12 months
 * within the contract, and pays by card on the page of POK or Stripe (App\Support\Pay\Gateways). Card details never
 * reach this server. A customer whose online payment is off (customer_profiles.online_pay) cannot pay here.
 *
 * Each service runs in test or live mode (Gateways::mode). A test payment is recorded under Online payments, but never
 * issues an invoice or renews a service: on a live server, a test card must not pay a real customer's bill.
 *
 * Whatever brings the customer or the service back (the redirect, the webhook, the 5-minute check), a payment counts
 * only after asking the service for the order and finding it completed, in the right currency and for the full amount.
 */
final class PayPage
{
    private const TEXTS = ['title', 'intro', 'footer'];

    public const LANGUAGES = ['sq' => 'Shqip', 'en' => 'English'];

    /** Fixed wording on the page, by language. The title, introduction and footer are the operator's. */
    public const LABELS = [
        'en' => [
            'default_title' => 'Pay your internet', 'default_intro' => 'Enter the username on your contract and the last 4 digits of your phone number to see your plan and pay by card.',
            'username' => 'Username', 'phone' => 'Last 4 digits of your phone number', 'find' => 'Find my account', 'plan' => 'Plan', 'customer' => 'Customer', 'paid_until' => 'Paid until', 'contract_until' => 'Contract until',
            'period' => 'How long do you want to pay for?', 'month' => 'month', 'months' => 'months', 'covers' => 'Covers', 'pay' => 'Pay', 'another' => 'Use another account',
            'not_found' => 'We could not find an active contract for this username and phone number. Check them, or contact us.',
            'locked' => 'Too many tries for this username. Wait 15 minutes, or contact us.',
            'no_online' => 'Online payment is not available for your account. Please contact us to pay.',
            'test_mode' => 'Test mode: no real money is taken, and nothing is paid.',
            'test_paid' => 'Test payment completed.', 'test_paid_detail' => 'No real money was taken, and your service did not change.',
            'no_period' => 'Your contract ends too soon to pay online. Please contact us to renew it.',
            'unavailable' => 'Online payment is not available right now. Try again later, or contact us.',
            'paid' => 'Payment received, thank you.', 'paid_detail' => 'Your service is paid until', 'invoice' => 'Invoice',
            'waiting' => 'We have not received the payment yet.', 'waiting_detail' => 'If you paid, this page will update in a few minutes. Nothing was charged twice.',
            'canceled' => 'The payment was not completed.', 'canceled_detail' => 'No money was taken. You can try again.',
            'review' => 'Your payment arrived and needs a quick check.', 'review_detail' => 'We will confirm it soon. Please contact us if your service does not renew.',
            'secure' => 'You pay on the secure page of the service you choose. Your card details do not reach us.', 'method' => 'Pay with', 'back' => 'Back to the start', 'refresh' => 'Check again',
        ],
        'sq' => [
            'default_title' => 'Paguaj internetin', 'default_intro' => 'Shkruaj emrin e përdoruesit nga kontrata dhe 4 shifrat e fundit të numrit të telefonit për të parë paketën dhe për të paguar me kartë.',
            'username' => 'Emri i përdoruesit', 'phone' => '4 shifrat e fundit të numrit të telefonit', 'find' => 'Gjej llogarinë', 'plan' => 'Paketa', 'customer' => 'Klienti', 'paid_until' => 'E paguar deri më', 'contract_until' => 'Kontrata deri më',
            'period' => 'Për sa kohë dëshiron të paguash?', 'month' => 'muaj', 'months' => 'muaj', 'covers' => 'Mbulon', 'pay' => 'Paguaj', 'another' => 'Përdor një llogari tjetër',
            'not_found' => 'Nuk gjetëm kontratë aktive për këtë emër përdoruesi dhe numër telefoni. Kontrolloji, ose na kontakto.',
            'locked' => 'Shumë përpjekje për këtë emër përdoruesi. Prit 15 minuta, ose na kontakto.',
            'no_online' => 'Pagesa online nuk është e mundur për llogarinë tënde. Na kontakto për të paguar.',
            'test_mode' => 'Mënyrë testi: nuk tërhiqen para të vërteta dhe asgjë nuk paguhet.',
            'test_paid' => 'Pagesa e testit u krye.', 'test_paid_detail' => 'Nuk u tërhoqën para të vërteta dhe shërbimi yt nuk ndryshoi.',
            'no_period' => 'Kontrata jote mbaron shumë shpejt për të paguar online. Na kontakto për ta rinovuar.',
            'unavailable' => 'Pagesa online nuk është e disponueshme tani. Provo më vonë, ose na kontakto.',
            'paid' => 'Pagesa u mor, faleminderit.', 'paid_detail' => 'Shërbimi yt është i paguar deri më', 'invoice' => 'Fatura',
            'waiting' => 'Nuk e kemi marrë ende pagesën.', 'waiting_detail' => 'Nëse ke paguar, kjo faqe përditësohet pas pak minutash. Nuk tërhiqet asgjë dy herë.',
            'canceled' => 'Pagesa nuk u krye.', 'canceled_detail' => 'Nuk u tërhoq asnjë shumë. Mund të provosh përsëri.',
            'review' => 'Pagesa jote erdhi dhe do një kontroll të shkurtër.', 'review_detail' => 'Do ta konfirmojmë së shpejti. Na kontakto nëse shërbimi nuk rinovohet.',
            'secure' => 'Paguan në faqen e sigurt të shërbimit që zgjedh. Të dhënat e kartës nuk vijnë te ne.', 'method' => 'Paguaj me', 'back' => 'Kthehu në fillim', 'refresh' => 'Kontrollo përsëri',
        ],
    ];

    /** @return array<string, string> */
    public static function settings(): array
    {
        return [
            'enabled' => Settings::value('pay_enabled', '0'),
            'host' => Settings::value('pay_host', ''),
            'language' => Settings::value('pay_language', 'sq'),
            'color' => Settings::value('pay_color', '#0d7f78'),
            'title' => Settings::value('pay_title', ''),
            'intro' => Settings::value('pay_intro', ''),
            'footer' => Settings::value('pay_footer', ''),
            'pok_enabled' => Settings::value('pok_enabled', '0'),
            'stripe_enabled' => Settings::value('stripe_enabled', '0'),
            'pok_mode' => Gateways::mode('pok'),
            'stripe_mode' => Gateways::mode('stripe'),
            'pok_test_merchant_id' => Gateways::credential('pok', 'test', 'merchant_id'),
            'pok_test_key_id' => Gateways::credential('pok', 'test', 'key_id'),
            'pok_live_merchant_id' => Gateways::credential('pok', 'live', 'merchant_id'),
            'pok_live_key_id' => Gateways::credential('pok', 'live', 'key_id'),
        ];
    }

    /**
     * Which secrets are saved (never the secrets themselves): pok_test, pok_live, stripe_test, stripe_live.
     *
     * @return array<string, bool>
     */
    public static function secretsSaved(): array
    {
        $saved = [];
        foreach (Gateways::FIELDS as $name => $fields) {
            foreach (array_keys(Gateways::MODES) as $mode) {
                $saved["{$name}_{$mode}"] = Gateways::credential($name, $mode, end($fields)) !== '';
            }
        }
        return $saved;
    }

    /** A suggestion for the address: pay. and the portal's domain, without its first label. */
    public static function suggestedHost(): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return 'pay.example.com';
        }
        $parts = explode('.', $host);
        return 'pay.' . implode('.', count($parts) > 2 ? array_slice($parts, 1) : $parts);
    }

    /** @param array<string, mixed> $input */
    public static function save(array $input): void
    {
        $host = strtolower(trim((string) ($input['host'] ?? '')));
        if ($host !== '' && !preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)) {
            throw new InvalidArgumentException('Address: enter a domain name such as pay.example.com, without https://.');
        }
        $portal = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host !== '' && $host === $portal) {
            throw new InvalidArgumentException('Address: the pay page needs its own address, not the portal\'s.');
        }
        $color = strtolower(trim((string) ($input['color'] ?? '')));
        if (!preg_match('/^#[0-9a-f]{6}$/D', $color)) {
            throw new InvalidArgumentException('Colour: choose a colour such as #0d7f78.');
        }
        $language = (string) ($input['language'] ?? 'sq');
        if (!isset(self::LANGUAGES[$language])) {
            throw new InvalidArgumentException('Choose a language for the page.');
        }
        $modes = [];
        foreach (array_keys(Gateways::NAMES) as $name) {
            $modes[$name] = (string) ($input[$name . '_mode'] ?? 'test');
            if (!isset(Gateways::MODES[$modes[$name]])) {
                throw new InvalidArgumentException(Gateways::NAMES[$name] . ': choose test or live.');
            }
        }
        foreach (['title' => 80, 'intro' => 400, 'footer' => 400] as $key => $max) {
            if (mb_strlen(trim((string) ($input[$key] ?? ''))) > $max) {
                throw new InvalidArgumentException(ucfirst($key) . ": at most {$max} characters.");
            }
        }
        // Each mode's credentials; an empty secret keeps the saved one.
        $values = [];
        foreach (Gateways::FIELDS as $name => $fields) {
            foreach (array_keys(Gateways::MODES) as $mode) {
                foreach ($fields as $field) {
                    $values["{$name}_{$mode}_{$field}"] = trim((string) ($input["{$name}_{$mode}_{$field}"] ?? ''));
                }
            }
        }
        foreach (['test', 'live'] as $mode) {
            $key = $values["stripe_{$mode}_secret_key"];
            if ($key !== '' && !preg_match('/^[sr]k_' . $mode . '_[A-Za-z0-9_]+$/D', $key)) {
                throw new InvalidArgumentException("Stripe {$mode}: enter the {$mode} secret key, which starts with sk_{$mode}_. The publishable key is not used.");
            }
        }
        $complete = static function (string $name, string $mode) use ($values): bool {
            foreach (Gateways::FIELDS[$name] as $i => $field) {
                $last = $i === array_key_last(Gateways::FIELDS[$name]);
                if ($values["{$name}_{$mode}_{$field}"] === '' && !($last && Gateways::credential($name, $mode, $field) !== '')) {
                    return false;
                }
            }
            return true;
        };
        $enabled = !empty($input['enabled']);
        $pok = !empty($input['pok_enabled']);
        $stripe = !empty($input['stripe_enabled']);
        if ($pok && !$complete('pok', $modes['pok'])) {
            throw new InvalidArgumentException('POK ' . $modes['pok'] . ': enter the merchant id, key id and key secret, or turn POK off.');
        }
        if ($stripe && !$complete('stripe', $modes['stripe'])) {
            throw new InvalidArgumentException('Stripe ' . $modes['stripe'] . ': enter the secret key, or turn Stripe off.');
        }
        if ($enabled && ($host === '' || (!$pok && !$stripe))) {
            throw new InvalidArgumentException('To turn the page on, enter its address and turn on POK or Stripe.');
        }
        Settings::save('pay_enabled', $enabled ? '1' : '0');
        Settings::save('pay_host', $host);
        Settings::save('pay_language', $language);
        Settings::save('pay_color', $color);
        foreach (self::TEXTS as $key) {
            Settings::save('pay_' . $key, trim(str_replace("\r", '', (string) ($input[$key] ?? ''))));
        }
        Settings::save('pok_enabled', $pok ? '1' : '0');
        Settings::save('stripe_enabled', $stripe ? '1' : '0');
        foreach ($modes as $name => $mode) {
            Settings::save($name . '_mode', $mode);
        }
        foreach (Gateways::FIELDS as $name => $fields) {
            $secret = end($fields);
            foreach ($values as $key => $value) {
                if (!str_starts_with($key, $name . '_')) {
                    continue;
                }
                if (str_ends_with($key, '_' . $secret)) {
                    if ($value !== '') {
                        Settings::save($key, $value, true);
                    }
                } else {
                    Settings::save($key, $value);
                }
            }
        }
    }

    /** True while a service the page offers runs in test mode: the page then says no real money is taken. */
    public static function testing(): bool
    {
        foreach (Gateways::available() as $name) {
            if (Gateways::mode($name) === 'test') {
                return true;
            }
        }
        return false;
    }

    /**
     * The customer the username and the last 4 digits of a phone number belong to (phone or cell phone), or null.
     * Online payment may still be off for them: see online_pay.
     *
     * @return array<string, mixed>|null
     */
    public static function identify(string $username, string $digits): ?array
    {
        $username = trim($username);
        $digits = (string) preg_replace('/\D/', '', $digits);
        if ($username === '' || mb_strlen($username) > 64 || strlen($digits) !== 4) {
            return null;
        }
        $customer = Db::row('SELECT username, phone, cell_phone, online_pay FROM customer_profiles WHERE username=? AND account_status<>"archived"', [$username]);
        if (!$customer) {
            return null;
        }
        $match = false;
        foreach ([$customer['phone'] ?? '', $customer['cell_phone'] ?? ''] as $phone) {
            $saved = (string) preg_replace('/\D/', '', (string) $phone);
            $match = (strlen($saved) >= 4 && hash_equals(substr($saved, -4), $digits)) || $match;
        }
        return $match ? $customer : null;
    }

    public static function enabled(): bool
    {
        return Settings::value('pay_enabled', '0') === '1' && Settings::value('pay_host', '') !== '' && License::allows('contracts') && Gateways::available() !== [];
    }

    public static function isPayHost(string $host): bool
    {
        $pay = Settings::value('pay_host', '');
        return $pay !== '' && strcasecmp($pay, $host) === 0;
    }

    /**
     * What the page shows, for the browser. No credentials.
     *
     * @return array{title: string, intro: string, footer: string, color: string, colorText: string, company: string, labels: array<string, string>, language: string, providers: list<array{id: string, name: string}>}
     */
    public static function view(): array
    {
        $s = self::settings();
        $labels = self::LABELS[$s['language']] ?? self::LABELS['en'];
        return [
            'title' => $s['title'] !== '' ? $s['title'] : $labels['default_title'],
            'intro' => $s['intro'] !== '' ? $s['intro'] : $labels['default_intro'],
            'footer' => $s['footer'],
            'color' => $s['color'],
            'colorText' => self::readableOn($s['color']),
            'company' => BillingDocuments::settings()['company_name'],
            'labels' => $labels,
            'language' => $s['language'],
            'providers' => array_map(static fn (string $name) => ['id' => $name, 'name' => Gateways::NAMES[$name]], Gateways::available()),
            'testing' => self::testing(),
        ];
    }

    /** Black or white, whichever reads better on the colour. */
    public static function readableOn(string $hex): string
    {
        [$r, $g, $b] = array_map(static function (string $part): float {
            $c = hexdec($part) / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b > 0.179 ? '#000000' : '#ffffff';
    }

    /** "Arben Hoxha" as "A**** H****": enough to recognise yourself, not enough to learn who a username belongs to. */
    public static function maskName(string $name): string
    {
        return implode(' ', array_map(static fn (string $w) => mb_substr($w, 0, 1) . str_repeat('*', max(1, min(4, mb_strlen($w) - 1))), array_filter(explode(' ', trim($name)))));
    }

    /**
     * What a customer may pay for: their plan and the periods that end within the contract. Null without an active
     * contract, a plan with a price, or a customer who can be billed.
     *
     * @return array{customer: array<string, mixed>, contract: array<string, mixed>, service: string, options: list<array<string, mixed>>}|null
     */
    public static function account(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }
        $customer = Db::row('SELECT c.*, s.name AS service_name, s.price FROM customer_profiles c JOIN service_metadata s ON s.group_name=c.service_group AND s.enabled=1
            WHERE c.username=? AND c.enabled=1 AND c.online_pay=1 AND c.account_status<>"archived" AND s.price>0', [$username]);
        $contract = $customer ? Contracts::active((string) $customer['username']) : null;
        if (!$customer || !$contract) {
            return null;
        }
        $options = [];
        foreach (Contracts::PERIODS as $period => $months) {
            try {
                $quote = BillingRepository::periodQuote((string) $customer['username'], $period);
            } catch (RuntimeException) {
                continue;
            }
            if ($quote['end'] <= $contract['end_date']) {
                $options[] = ['period' => $period, 'months' => $months] + $quote;
            }
        }
        return ['customer' => $customer, 'contract' => $contract, 'service' => (string) $customer['service_name'], 'options' => $options];
    }

    /** Starts a payment and returns the service's page for it. An open one for the same period and service is used again. */
    public static function start(string $username, string $period, string $provider, callable $returnUrl, string $webhookUrl): string
    {
        if (!in_array($provider, Gateways::available(), true)) {
            throw new RuntimeException('This payment service is not available.');
        }
        $account = self::account($username);
        $option = null;
        foreach ($account['options'] ?? [] as $candidate) {
            if ($candidate['period'] === $period) {
                $option = $candidate;
            }
        }
        if (!$option) {
            throw new RuntimeException('This period is not available.');
        }
        $customer = $account['customer'];
        $mode = Gateways::mode($provider);
        $open = Db::row('SELECT * FROM pay_payments WHERE username=? AND provider=? AND mode=? AND billing_period=? AND period_start=? AND amount=? AND status="pending" AND checkout_url IS NOT NULL AND expires_at>DATE_ADD(NOW(), INTERVAL 5 MINUTE) ORDER BY id DESC LIMIT 1',
            [$customer['username'], $provider, $mode, $period, $option['start'], $option['amount']]);
        if ($open) {
            return (string) $open['checkout_url'];
        }
        $token = bin2hex(random_bytes(20));
        Db::exec('INSERT INTO pay_payments (token,username,contract_id,provider,mode,billing_period,period_start,period_end,amount,currency,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,"pending",NOW(),NOW())',
            [$token, $customer['username'], $account['contract']['id'], $provider, $mode, $period, $option['start'], $option['end'], $option['amount'], $option['currency']]);
        $id = (int) Db::pdo()->lastInsertId();
        try {
            $page = Gateways::create($provider, $mode, [
                'token' => $token,
                'reference' => 'pay-' . $id,
                'amount' => (float) $option['amount'],
                'currency' => (string) $option['currency'],
                'description' => BillingDocuments::settings()['company_name'] . ", {$account['service']}, {$option['months']} x month, {$customer['username']}",
                'return_url' => $returnUrl($token),
                'webhook_url' => $webhookUrl,
            ]);
            Db::exec('UPDATE pay_payments SET provider_order_id=?, checkout_url=?, expires_at=?, updated_at=NOW() WHERE id=?', [$page['id'], $page['url'], $page['expires'], $id]);
            return $page['url'];
        } catch (Throwable $e) {
            Db::exec('UPDATE pay_payments SET status="failed", note=?, updated_at=NOW() WHERE id=?', [mb_substr($e->getMessage(), 0, 255), $id]);
            throw $e;
        }
    }

    /**
     * Asks the service how a payment stands, and when it is completed for the full amount issues the invoice and records
     * the payment, which renews the service. Returns paid | waiting | canceled | mismatch. A test payment is only recorded.
     *
     * @param  array<string, mixed>  $payment
     */
    public static function sync(array $payment): string
    {
        if ($payment['status'] === 'paid' || $payment['status'] === 'mismatch') {
            return (string) $payment['status'];
        }
        if ($payment['status'] !== 'pending' || empty($payment['provider_order_id'])) {
            return 'canceled';
        }
        $status = Gateways::status((string) $payment['provider'], (string) ($payment['mode'] ?? 'test'), (string) $payment['provider_order_id']);
        if ($status['state'] === 'canceled') {
            self::mark((int) $payment['id'], 'canceled', $status['note']);
            return 'canceled';
        }
        if ($status['state'] === 'waiting') {
            if (!empty($payment['expires_at']) && strtotime((string) $payment['expires_at']) < time()) {
                self::mark((int) $payment['id'], 'expired', 'The payment page expired without a payment.');
                return 'canceled';
            }
            return 'waiting';
        }
        if ($status['currency'] !== $payment['currency'] || abs($status['amount'] - (float) $payment['amount']) > 0.005) {
            return self::mismatch($payment, "Completed for {$status['amount']} {$status['currency']}; expected {$payment['amount']} {$payment['currency']}.");
        }
        return self::settle($payment, $status['reference']);
    }

    /** @param array<string, mixed> $payment */
    private static function settle(array $payment, string $reference): string
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $locked = Db::row('SELECT * FROM pay_payments WHERE id=? FOR UPDATE', [$payment['id']]);
            if (!$locked || $locked['status'] !== 'pending') {
                $pdo->commit();
                SubscriberPolicy::releaseLocks();
                return (string) ($locked['status'] ?? 'waiting');
            }
            if (($locked['mode'] ?? 'test') !== 'live') {
                // A test card: recorded so the whole round trip can be checked, but no invoice and no renewal.
                Db::exec('UPDATE pay_payments SET status="paid", note=?, paid_at=NOW(), updated_at=NOW() WHERE id=?', [mb_substr('Test payment (' . Gateways::NAMES[$locked['provider']] . ' ' . $reference . '): no invoice issued, the service was not renewed.', 0, 255), $locked['id']]);
                Audit::log('payment_test', 'pay_page', (string) $locked['id'], null, ['username' => $locked['username'], 'amount' => $locked['amount'], 'provider' => $locked['provider'], 'order' => $locked['provider_order_id']]);
                $pdo->commit();
                SubscriberPolicy::releaseLocks();
                return 'paid';
            }
            $invoice = BillingRepository::issuePeriodInvoice((string) $locked['username'], (string) $locked['billing_period'], 'Paid online, ' . Gateways::NAMES[$locked['provider']] . ' order ' . $locked['provider_order_id'] . '.', (string) $locked['period_start']);
            $row = BillingRepository::invoice($invoice);
            if (abs((float) $row['total_amount'] - (float) $locked['amount']) > 0.005 || $row['currency'] !== $locked['currency']) {
                throw new RuntimeException("The invoice would be {$row['total_amount']} {$row['currency']}; the customer paid {$locked['amount']} {$locked['currency']}.");
            }
            BillingRepository::recordPayment($invoice, [
                'amount' => $locked['amount'], 'currency' => $locked['currency'], 'method' => 'card', 'payment_date' => Entitlement::today(),
                'reference' => mb_substr(Gateways::NAMES[$locked['provider']] . ' ' . $reference, 0, 120), 'notes' => 'Paid on the pay page.', 'request_key' => 'pay-' . $locked['token'],
            ]);
            Db::exec('UPDATE pay_payments SET status="paid", invoice_id=?, paid_at=NOW(), updated_at=NOW() WHERE id=?', [$invoice, $locked['id']]);
            Audit::log('payment', 'pay_page', (string) $locked['id'], null, ['username' => $locked['username'], 'invoice' => $invoice, 'amount' => $locked['amount'], 'provider' => $locked['provider'], 'order' => $locked['provider_order_id']]);
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
            return 'paid';
        } catch (PDOException $e) {
            // A database problem, not a problem with the payment: it stays open and the next check tries again.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                SubscriberPolicy::releaseLocks();
            }
            throw $e;
        } catch (RuntimeException $e) {
            // The money was taken but this cannot be applied by itself: it needs a person, and nothing may be half done.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                SubscriberPolicy::releaseLocks();
            }
            return self::mismatch($payment, $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                SubscriberPolicy::releaseLocks();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $payment */
    private static function mismatch(array $payment, string $why): string
    {
        Log::error("Pay page payment {$payment['id']} needs review: {$why}");
        self::mark((int) $payment['id'], 'mismatch', $why);
        return 'mismatch';
    }

    private static function mark(int $id, string $status, string $note): void
    {
        Db::exec('UPDATE pay_payments SET status=?, note=?, updated_at=NOW() WHERE id=? AND status="pending"', [$status, mb_substr($note, 0, 255), $id]);
    }

    /** Checks the payments still open (a webhook can be missed). Returns how many became paid. */
    public static function syncOpen(): int
    {
        $paid = 0;
        foreach (Db::select('SELECT * FROM pay_payments WHERE status="pending" AND provider_order_id IS NOT NULL AND created_at<DATE_SUB(NOW(), INTERVAL 2 MINUTE) ORDER BY id LIMIT 100') as $payment) {
            try {
                $paid += self::sync($payment) === 'paid' ? 1 : 0;
            } catch (Throwable $e) {
                Log::warning('Pay page: could not check payment ' . $payment['id'] . ': ' . $e->getMessage());
            }
        }
        return $paid;
    }
}
