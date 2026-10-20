<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Support\Clients;
use App\Http\Support\Format;
use App\Http\Support\Invoices;
use InvalidArgumentException;

/**
 * Wording of the payment request (draft PDF) and payment receipt (Settings → Invoices & receipts).
 * A blank field uses the default. Titles, notes and footers may use the placeholders in PLACEHOLDERS.
 */
final class BillingDocuments
{
    /** key => [label, default, max characters, max lines] */
    public const FIELDS = [
        'company_name' => ['Company name', '', 60, 1],
        'company_details' => ['Company details', '', 300, 4],
        'draft_title' => ['Payment request title', 'DRAFT PAYMENT REQUEST', 40, 1],
        'draft_note' => ['Payment instructions', '', 500, 6],
        'draft_footer' => ['Payment request footer', 'This document is a payment request, not a receipt.', 120, 1],
        'receipt_title' => ['Receipt title', 'PAYMENT RECEIPT', 40, 1],
        'receipt_partial_title' => ['Partial receipt title', 'PARTIAL PAYMENT RECEIPT', 40, 1],
        'receipt_note' => ['Receipt note', 'Thank you for your payment.', 500, 6],
        'receipt_footer' => ['Receipt footer', 'Confirms recorded payments only; see amount paid and remaining balance.', 120, 1],
    ];
    public const PLACEHOLDERS = ['invoice', 'customer', 'username', 'total', 'paid', 'balance', 'due', 'issued', 'period'];
    private const PREFIX = 'billing_pdf_';

    /** @return array<string, string> saved wording, blank where the default applies */
    public static function saved(): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $values[$key] = Settings::value(self::PREFIX . $key, '');
        }
        return $values;
    }

    /** @return array<string, string> the default wording (the company name defaults to the portal name) */
    public static function defaults(): array
    {
        return ['company_name' => Branding::name()] + array_map(static fn (array $f) => $f[1], self::FIELDS);
    }

    /** @return array<string, string> the wording in use (saved, or the default) */
    public static function settings(): array
    {
        $defaults = self::defaults();
        $values = self::saved();
        foreach ($values as $key => $value) {
            $values[$key] = $value !== '' ? $value : $defaults[$key];
        }
        return $values;
    }

    public static function save(array $input): void
    {
        $clean = [];
        foreach (self::FIELDS as $key => [$label, , $max, $lines]) {
            $value = trim(str_replace("\r", '', (string) ($input[$key] ?? '')));
            $value = implode("\n", array_map('rtrim', explode("\n", $value)));
            if (mb_strlen($value) > $max) {
                throw new InvalidArgumentException("$label: at most $max characters.");
            }
            if (substr_count($value, "\n") + 1 > $lines) {
                throw new InvalidArgumentException("$label: at most $lines line" . ($lines === 1 ? '' : 's') . '.');
            }
            if (preg_match('/\{(?!(?:' . implode('|', self::PLACEHOLDERS) . ')\})[a-z_]*\}/', $value, $m)) {
                throw new InvalidArgumentException("$label: unknown placeholder {$m[0]}.");
            }
            $clean[$key] = $value;
        }
        $defaults = self::defaults();
        foreach ($clean as $key => $value) {
            // The default text, shown pre-filled, stays "the default" so a later change of default applies.
            Settings::save(self::PREFIX . $key, $value === $defaults[$key] ? '' : $value);
        }
    }

    /** Fills the placeholders for one invoice. */
    public static function fill(string $text, array $invoice): string
    {
        $currency = ' ' . $invoice['currency'];
        $values = [
            'invoice' => (string) $invoice['invoice_number'],
            'customer' => Clients::displayName($invoice),
            'username' => (string) $invoice['username'],
            'total' => Format::money($invoice['total_amount']) . $currency,
            'paid' => Format::money($invoice['amount_paid']) . $currency,
            'balance' => Format::money($invoice['balance_due']) . $currency,
            'due' => (string) $invoice['due_date'],
            'issued' => (string) $invoice['issue_date'],
            'period' => Invoices::periodLabel((string) ($invoice['billing_period'] ?? 'monthly')),
        ];
        return strtr($text, array_combine(array_map(static fn ($k) => '{' . $k . '}', array_keys($values)), $values));
    }

    /** A made-up invoice for the Preview buttons, so the layout can be checked before any real invoice exists. */
    public static function sample(bool $paid): array
    {
        return [
            'id' => 0, 'invoice_number' => 'INV-' . date('Y') . '-0001', 'username' => 'arben.hoxha', 'first_name' => 'Arben', 'last_name' => 'Hoxha',
            'phone' => '+355 69 123 4567', 'email' => 'arben@example.com', 'service_name' => 'Home 50', 'service_group' => 'home50', 'billing_period' => 'monthly',
            'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+30 days')), 'status' => $paid ? 'paid' : 'open', 'currency' => Currency::code(),
            'subtotal' => '20.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total_amount' => '20.00',
            'amount_paid' => $paid ? '20.00' : '0.00', 'balance_due' => $paid ? '0.00' : '20.00',
        ];
    }
}
