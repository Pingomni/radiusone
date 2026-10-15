<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\BillingCalendar;

/** Invoice presentation shared by Billing and the client Billing tab (legacy app/Http/billing.php). */
final class Invoices
{
    public const PERIODS = ['monthly' => '1 month', '2_months' => '2 months', '3_months' => '3 months', '6_months' => '6 months', 'yearly' => '12 months'];

    public static function period(string $period): string
    {
        return isset(self::PERIODS[$period]) ? $period : 'monthly';
    }

    public static function periodLabel(string $period): string
    {
        return self::PERIODS[self::period($period)];
    }

    public static function dueDate(string $start, string $period): string
    {
        return BillingCalendar::end($start, $period);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft · not issued',
            'open' => 'Unpaid',
            'partial' => 'Partially paid',
            'overdue' => 'Overdue',
            'paid' => 'Paid',
            'void' => 'Cancelled',
            default => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'open', 'partial' => 'warning',
            'overdue' => 'danger',
            'draft' => 'info',
            default => '',
        };
    }

    /** Badge used in filters and lists keyed by raw status. */
    public static function filterTone(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'overdue', 'void' => 'danger',
            'partial', 'open' => 'warning',
            default => '',
        };
    }

    public static function paymentTone(string $status): string
    {
        return match ($status) {
            'current' => 'success',
            'overdue' => 'danger',
            'due' => 'warning',
            default => '',
        };
    }

    /** Status cell data (resources/js/components/billing.tsx <InvoiceStatus>). */
    public static function status(array $invoice): array
    {
        $status = (string) $invoice['status'];
        $payable = !in_array($status, ['draft', 'void'], true);
        return [
            'status' => $status,
            'label' => self::statusLabel($status),
            'tone' => self::statusTone($status),
            'payable' => $payable,
            'note' => $payable
                ? Format::money($invoice['amount_paid']) . ' of ' . Format::money($invoice['total_amount']) . ' paid'
                : ($status === 'draft' ? 'Not billed yet' : 'Nothing to collect'),
            'total' => (float) $invoice['total_amount'],
            'paid' => (float) $invoice['amount_paid'],
        ];
    }

    /** "Payment receipt" link, or null when nothing has been paid on an issued invoice. */
    public static function receipt(array $invoice): ?array
    {
        if (in_array($invoice['status'], ['draft', 'void'], true) || (float) $invoice['amount_paid'] <= 0) {
            return null;
        }
        return ['href' => '/billing/invoices/' . (int) $invoice['id'] . '/payment-pdf', 'label' => (float) $invoice['balance_due'] > 0 ? 'Partial payment receipt' : 'Payment receipt'];
    }

    /** List row shared by the client Billing tab and the Billing list. */
    public static function row(array $invoice): array
    {
        $payable = !in_array($invoice['status'], ['draft', 'void'], true);
        return [
            'id' => (int) $invoice['id'],
            'number' => (string) $invoice['invoice_number'],
            'username' => (string) $invoice['username'],
            'period' => self::periodLabel((string) ($invoice['billing_period'] ?? 'monthly')),
            'issued' => (string) $invoice['issue_date'],
            'due' => (string) $invoice['due_date'],
            'total' => Format::money($invoice['total_amount']) . ' ' . $invoice['currency'],
            'paid' => $payable ? Format::money($invoice['amount_paid']) : '-',
            'balance' => $payable ? Format::money($invoice['balance_due']) : '-',
            'status' => self::status($invoice),
            'draftPdf' => $invoice['status'] !== 'void',
            'receipt' => self::receipt($invoice),
        ];
    }
}
