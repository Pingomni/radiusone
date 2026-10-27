<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\BillingCalendar;
use App\Support\BillingDocuments;
use App\Support\Branding;
use App\Support\License;
use App\Support\Settings;
use App\Support\SimplePdf;

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

    /** One sentence that answers "does the customer still owe anything on this invoice?". */
    public static function banner(array $invoice, array $payments): array
    {
        $currency = ' ' . $invoice['currency'];
        $balance = Format::money($invoice['balance_due']) . $currency;
        $paid = Format::money($invoice['amount_paid']) . $currency;
        $lastPayment = $payments ? max(array_column($payments, 'payment_date')) : '';
        [$tone, $title, $text] = match ((string) $invoice['status']) {
            'draft' => ['info', 'Draft — not issued to the customer', 'This invoice is not billed yet and does not count toward the customer balance. Use Edit draft, then Issue invoice to bill the customer.'],
            'open' => ['warning', 'Unpaid — ' . $balance . ' due', 'No payment recorded yet. Payment is due by ' . $invoice['due_date'] . '.'],
            'partial' => ['warning', 'Partially paid — ' . $balance . ' still due', $paid . ' received so far. The remainder is due by ' . $invoice['due_date'] . '.'],
            'overdue' => ['danger', 'Overdue — ' . $balance . ' outstanding', 'The due date ' . $invoice['due_date'] . ' has passed' . ((float) $invoice['amount_paid'] > 0 ? ' with ' . $paid . ' paid.' : ' and nothing has been paid.')],
            'paid' => ['success', 'Paid in full', Format::money($invoice['total_amount']) . $currency . ' received' . ($lastPayment !== '' ? '; last payment on ' . $lastPayment : '') . '. Nothing is owed on this invoice.'],
            'void' => ['neutral', 'Cancelled', 'This invoice was voided. The customer does not owe anything on it.'],
            default => ['neutral', ucfirst((string) $invoice['status']), ''],
        };
        return ['tone' => $tone, 'title' => $title, 'text' => $text];
    }

    /**
     * Payment request ($payments empty) or receipt PDF, worded as in Settings → Invoices & receipts.
     * $paymentState: '' for a payment request, 'paid' or 'partial' for a receipt.
     */
    public static function pdf(array $invoice, array $items, array $payments, string $paymentState = ''): string
    {
        $words = BillingDocuments::settings();
        $receipt = $paymentState !== '';
        $title = $receipt ? ($paymentState === 'paid' ? $words['receipt_title'] : $words['receipt_partial_title']) : $words['draft_title'];
        $note = $receipt ? $words['receipt_note'] : $words['draft_note'];
        $footer = $receipt ? $words['receipt_footer'] : $words['draft_footer'];
        $currency = (string) $invoice['currency'];
        $period = self::periodLabel((string) ($invoice['billing_period'] ?? 'monthly'));
        $money = static fn ($value) => Format::money($value) . ' ' . $currency;
        $logo = License::allows('branding') ? Settings::value('portal_logo_path', '') : '';
        return SimplePdf::receipt([
            'title' => BillingDocuments::fill($title, $invoice),
            'payment_state' => $paymentState,
            'subtitle' => 'Generated on ' . date('Y-m-d H:i'),
            'app_name' => $words['company_name'],
            'company_details' => $words['company_details'] !== '' ? explode("\n", $words['company_details']) : [],
            'note' => $note !== '' ? explode("\n", BillingDocuments::fill($note, $invoice)) : [],
            // An uploaded logo wins; otherwise the RadiusOne logo (white background: the PDF writer drops transparency).
            'logo_path' => str_starts_with($logo, '/uploads/branding/') ? Branding::file(basename($logo)) : ($logo === '' ? public_path('brand/logo-invoice.png') : null),
            'meta' => [
                'Invoice' => (string) $invoice['invoice_number'],
                'Customer' => Clients::displayName($invoice),
                'Username' => (string) $invoice['username'],
                'Phone' => (string) (($invoice['phone'] ?? '') ?: '-'),
                'Email' => (string) (($invoice['email'] ?? '') ?: '-'),
                'Service' => (string) (($invoice['service_name'] ?? '') ?: ($invoice['service_group'] ?? '-')),
                'Period' => $period,
                'Issued' => (string) $invoice['issue_date'],
                'Due' => (string) $invoice['due_date'],
                'Status' => strtoupper((string) $invoice['status']),
            ],
            'items' => array_map(static fn ($item) => [(string) $item['description'], $period, $money($item['line_total'])], $items),
            'payments' => array_map(static fn ($p) => [(string) $p['payment_date'], Format::money($p['amount']) . ' ' . $p['currency'], (string) $p['method'], (string) (($p['reference'] ?? '') ?: '-')], $payments),
            'totals' => [
                'Subtotal' => $money($invoice['subtotal']),
                'Discount' => $money($invoice['discount_amount']),
                'Tax' => $money($invoice['tax_amount']),
                'Invoice total' => $money($invoice['total_amount']),
                'Amount paid' => $money($invoice['amount_paid']),
                'Balance due' => $money($invoice['balance_due']),
            ],
            'footer' => BillingDocuments::fill($footer, $invoice),
        ]);
    }
}
