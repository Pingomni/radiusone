<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Support\Clients;
use App\Http\Support\Format;
use InvalidArgumentException;
use RuntimeException;

/**
 * Customer contracts (RadiusOne Pro). A contract ties a customer to a plan, a term and a billing period. Its text comes
 * from the template under Settings → Contracts and is stored as issued, so a later change of template leaves it alone.
 * The term limits what the pay page offers: a customer cannot pay for time past the end of the contract.
 */
final class Contracts
{
    /** The billing periods a contract or the pay page can use: name => months. */
    public const PERIODS = ['monthly' => 1, '3_months' => 3, '6_months' => 6, 'yearly' => 12];

    public const PLACEHOLDERS = [
        'contract_number', 'customer', 'username', 'company', 'address', 'city', 'phone', 'email', 'tax_id',
        'plan', 'download', 'upload', 'monthly_price', 'currency', 'billing_period', 'start_date', 'end_date', 'term_months', 'today',
    ];

    public const MAX_TEMPLATE = 20000;

    private const TEMPLATE_KEY = 'contract_template';

    public const DEFAULT_TEMPLATE = <<<'TEXT'
# Parties
Provider: {company}
Customer: {customer}
Address: {address}, {city}
Phone: {phone}
Email: {email}
Tax ID: {tax_id}

# Service
The provider supplies internet access under the plan {plan}: {download} Mbps download and {upload} Mbps upload.
Your connection username is {username}.

# Price and payment
The price is {monthly_price} {currency} per month. It is billed {billing_period} and paid in advance.
The service may be suspended if an invoice is not paid by its due date.

# Term
This contract starts on {start_date} and ends on {end_date} ({term_months} months).

# Signatures
Date: {today}


Provider: ______________________          Customer: ______________________
TEXT;

    public static function template(): string
    {
        $saved = Settings::value(self::TEMPLATE_KEY, '');
        return $saved !== '' ? $saved : self::DEFAULT_TEMPLATE;
    }

    /** The heading on the PDF, in the language of the template. */
    public static function title(): string
    {
        return Settings::value('contract_title', '') ?: 'CONTRACT';
    }

    public static function saveTemplate(string $text, string $title = ''): void
    {
        $title = trim($title);
        if (mb_strlen($title) > 40) {
            throw new InvalidArgumentException('The contract title can have at most 40 characters.');
        }
        $text = trim(str_replace("\r", '', $text));
        if ($text === '') {
            throw new InvalidArgumentException('The contract template cannot be empty.');
        }
        if (mb_strlen($text) > self::MAX_TEMPLATE) {
            throw new InvalidArgumentException('The contract template can have at most ' . self::MAX_TEMPLATE . ' characters.');
        }
        if (preg_match('/\{(?!(?:' . implode('|', self::PLACEHOLDERS) . ')\})[a-z_]*\}/', $text, $m)) {
            throw new InvalidArgumentException("Unknown placeholder {$m[0]}.");
        }
        Settings::save(self::TEMPLATE_KEY, $text === self::DEFAULT_TEMPLATE ? '' : $text);
        Settings::save('contract_title', $title);
    }

    public static function periodLabel(string $period): string
    {
        return match ($period) {
            'monthly' => 'monthly', '3_months' => 'every 3 months', '6_months' => 'every 6 months', 'yearly' => 'yearly',
            default => $period,
        };
    }

    /**
     * The template filled in for a customer.
     *
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $service
     */
    public static function render(string $template, array $customer, array $contract, array $service = []): string
    {
        $values = [
            'contract_number' => (string) $contract['contract_number'],
            'customer' => Clients::displayName($customer),
            'username' => (string) ($customer['username'] ?? ''),
            'company' => BillingDocuments::settings()['company_name'],
            'address' => (string) ($customer['address'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
            'phone' => (string) (($customer['phone'] ?? '') ?: ($customer['cell_phone'] ?? '')),
            'email' => (string) ($customer['email'] ?? ''),
            'tax_id' => (string) ($customer['vat_tax_id'] ?? ''),
            'plan' => (string) $contract['service_name'],
            'download' => (string) ($service['download_speed'] ?? ''),
            'upload' => (string) ($service['upload_speed'] ?? ''),
            'monthly_price' => Format::money($contract['monthly_price']),
            'currency' => (string) $contract['currency'],
            'billing_period' => self::periodLabel((string) $contract['billing_period']),
            'start_date' => (string) $contract['start_date'],
            'end_date' => (string) $contract['end_date'],
            'term_months' => (string) $contract['term_months'],
            'today' => Entitlement::today(),
        ];
        return strtr($template, array_combine(array_map(static fn ($k) => '{' . $k . '}', array_keys($values)), $values));
    }

    /**
     * The customer's contract in force today, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function active(string $username): ?array
    {
        return Db::row('SELECT * FROM customer_contracts WHERE username=? AND status="active" AND start_date<=? AND end_date>=? LIMIT 1',
            [$username, Entitlement::today(), Entitlement::today()]) ?: null;
    }

    /**
     * Issues a contract and, when asked, its first invoice for the billing period.
     *
     * @param  array<string, mixed>  $input  username, term_months, billing_period, start_date, issue_invoice
     */
    public static function create(array $input): int
    {
        SchemaCompatibility::require('contracts');
        $username = trim((string) ($input['username'] ?? ''));
        $term = (int) ($input['term_months'] ?? 0);
        $period = (string) ($input['billing_period'] ?? '');
        if ($term < 1 || $term > 120) {
            throw new RuntimeException('The term must be between 1 and 120 months.');
        }
        if (!isset(self::PERIODS[$period])) {
            throw new RuntimeException('Choose a billing period of 1, 3, 6 or 12 months.');
        }
        if (self::PERIODS[$period] > $term) {
            throw new RuntimeException('The billing period cannot be longer than the term of the contract.');
        }
        $start = trim((string) ($input['start_date'] ?? '')) ?: Entitlement::today();
        BillingCalendar::date($start);
        $end = self::termEnd($start, $term);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $customer = Db::row('SELECT * FROM customer_profiles WHERE username=? AND account_status<>"archived" FOR UPDATE', [$username]);
            if (!$customer) {
                throw new RuntimeException('Select a valid customer.');
            }
            $service = Db::row('SELECT * FROM service_metadata WHERE group_name=? AND enabled=1', [(string) $customer['service_group']]);
            if (!$service) {
                throw new RuntimeException('This customer has no enabled service plan. Choose a plan on the client first.');
            }
            if (Db::row('SELECT id FROM customer_contracts WHERE username=? AND status="active" FOR UPDATE', [$username])) {
                throw new RuntimeException('This customer already has an active contract. Cancel it before issuing a new one.');
            }
            $actor = Auth::user();
            $row = [
                'contract_number' => 'PENDING-' . bin2hex(random_bytes(8)), 'service_name' => (string) ($service['name'] ?: $service['group_name']),
                'monthly_price' => $service['price'], 'currency' => strtoupper((string) ($service['currency'] ?: Currency::code())),
                'billing_period' => $period, 'start_date' => $start, 'end_date' => $end, 'term_months' => $term,
            ];
            Db::exec('INSERT INTO customer_contracts (contract_number,username,service_group,service_name,monthly_price,currency,billing_period,start_date,end_date,term_months,status,body,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,"active","",?,NOW(),NOW())',
                [$row['contract_number'], $username, $service['group_name'], $row['service_name'], $row['monthly_price'], $row['currency'], $period, $start, $end, $term, $actor['id'] ?? null]);
            $id = (int) $pdo->lastInsertId();
            $row['contract_number'] = 'CNT-' . substr($start, 0, 4) . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            Db::exec('UPDATE customer_contracts SET contract_number=?, body=? WHERE id=?', [$row['contract_number'], self::render(self::template(), $customer, $row, $service), $id]);
            Db::exec('UPDATE customer_profiles SET contract_id=?, contract_valid_until=?, updated_at=NOW() WHERE username=?', [$row['contract_number'], $end, $username]);
            if (!empty($input['issue_invoice']) && (float) $service['price'] > 0) {
                $quote = BillingRepository::periodQuote($username, $period);
                if ($quote['end'] > $end) {
                    throw new RuntimeException('The first invoice would run past the end of the contract (' . $quote['end'] . '). Choose a shorter billing period.');
                }
                $invoice = BillingRepository::issuePeriodInvoice($username, $period, 'Contract ' . $row['contract_number'] . '.');
                Db::exec('UPDATE customer_contracts SET first_invoice_id=? WHERE id=?', [$invoice, $id]);
            }
            Audit::log('create', 'contract', (string) $id, null, ['number' => $row['contract_number'], 'username' => $username, 'term_months' => $term, 'billing_period' => $period]);
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                SubscriberPolicy::releaseLocks();
            }
            throw $e;
        }
    }

    /** The day before the same date $months later: a 12-month contract from 5 Jan ends on 4 Jan next year. */
    public static function termEnd(string $start, int $months): string
    {
        $day = BillingCalendar::date($start);
        $first = $day->modify('first day of this month')->modify("+{$months} months");
        $same = $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min((int) $day->format('d'), (int) $first->format('t')));
        return $same->modify('-1 day')->format('Y-m-d');
    }

    public static function cancel(int $id): void
    {
        $contract = Db::row('SELECT * FROM customer_contracts WHERE id=?', [$id]);
        if (!$contract || $contract['status'] !== 'active') {
            throw new RuntimeException('Only an active contract can be cancelled.');
        }
        Db::exec('UPDATE customer_contracts SET status="cancelled", cancelled_at=NOW(), updated_at=NOW() WHERE id=?', [$id]);
        Db::exec('UPDATE customer_profiles SET contract_valid_until=NULL, updated_at=NOW() WHERE username=? AND contract_id=?', [$contract['username'], $contract['contract_number']]);
        Audit::log('cancel', 'contract', (string) $id, ['status' => 'active'], ['status' => 'cancelled']);
    }

    /** Contracts past their end date are ended; returns how many. */
    public static function endExpired(): int
    {
        $today = Entitlement::today();
        $count = (int) Db::value('SELECT COUNT(*) FROM customer_contracts WHERE status="active" AND end_date<?', [$today]);
        if ($count) {
            Db::exec('UPDATE customer_contracts SET status="ended", updated_at=NOW() WHERE status="active" AND end_date<?', [$today]);
        }
        return $count;
    }

    /** @param array<string, mixed> $contract */
    public static function pdf(array $contract): string
    {
        $words = BillingDocuments::settings();
        $logo = License::allows('branding') ? Settings::value('portal_logo_path', '') : '';
        return SimplePdf::contract([
            'title' => self::title(),
            'subtitle' => $contract['contract_number'] . ' · ' . $contract['start_date'] . ' – ' . $contract['end_date'],
            'app_name' => $words['company_name'],
            'company_details' => $words['company_details'] !== '' ? explode("\n", $words['company_details']) : [],
            'logo_path' => str_starts_with($logo, '/uploads/branding/') ? Branding::file(basename($logo)) : null,
            'body' => (string) $contract['body'],
        ]);
    }

    /**
     * A made-up contract, so the template can be checked before any real contract exists.
     *
     * @return array<string, mixed>
     */
    public static function sample(string $template): array
    {
        $contract = [
            'contract_number' => 'CNT-' . date('Y') . '-000001', 'service_name' => 'Home 50', 'monthly_price' => '20.00', 'currency' => Currency::code(),
            'billing_period' => '3_months', 'start_date' => Entitlement::today(), 'end_date' => self::termEnd(Entitlement::today(), 12), 'term_months' => 12,
        ];
        $customer = ['username' => 'arben.hoxha', 'first_name' => 'Arben', 'last_name' => 'Hoxha', 'address' => 'Rruga e Kavajës 10', 'city' => 'Tiranë',
            'phone' => '+355 69 123 4567', 'email' => 'arben@example.com', 'vat_tax_id' => ''];
        return $contract + ['body' => self::render($template, $customer, $contract, ['download_speed' => '50', 'upload_speed' => '10'])];
    }
}
