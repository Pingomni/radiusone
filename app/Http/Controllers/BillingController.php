<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Clients;
use App\Http\Support\Csv;
use App\Http\Support\Format;
use App\Http\Support\Invoices;
use App\Http\Support\Present;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\BillingRepository;
use App\Support\Db;
use App\Support\Diagnostics;
use App\Support\Entitlement;
use App\Support\InvoiceQuery;
use App\Support\JobRunner;
use App\Support\StaticIpPool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Billing: invoice list and export, invoice form/view/PDFs, payments, recurring generation. */
final class BillingController extends Controller
{
    private const FILTERS = ['q', 'status'];
    public const PAYMENT_METHODS = ['cash' => 'cash', 'bank_transfer' => 'bank transfer', 'mobile_money' => 'mobile money', 'card' => 'card', 'check' => 'check', 'other' => 'other'];

    private const FIELD_ERRORS = [
        'username' => '/^Select a valid billing customer/',
        'service_group' => '/^(Select an enabled service plan|Service changed)/',
        'static_ip_pool_key' => '/^(Select a priced static IP pool|Static IP charges|Static IP pool currency)/',
        'currency' => '/^(Invoice currency|Issued customer invoices must use one currency)/',
        'discount_amount' => '/^(Invoice total is outside|Invalid monetary amount)/',
        'issue_date' => '/^Invalid billing date/',
        'billing_period' => '/^Invalid billing period/',
    ];

    public function index(Request $request): Response
    {
        $filters = array_map(static fn ($v) => is_scalar($v) ? trim((string) $v) : '', $request->only(self::FILTERS)) + array_fill_keys(self::FILTERS, '');
        $result = InvoiceQuery::page($request->query(), (int) $request->query('page', 1));
        $totals = Db::row(
            'SELECT
                COUNT(*) AS invoices,
                COALESCE(SUM(CASE WHEN status NOT IN ("paid", "void", "draft") THEN balance_due ELSE 0 END),0) AS outstanding,
                COALESCE(SUM(CASE WHEN status = "overdue" THEN balance_due ELSE 0 END),0) AS overdue
             FROM billing_invoices'
        ) ?: ['invoices' => 0, 'outstanding' => 0, 'overdue' => 0];
        // Money actually received this month, including partial payments and payments on older invoices.
        $totals['paid_month'] = Db::value('SELECT COALESCE(SUM(amount),0) FROM billing_payments WHERE payment_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")');
        $manage = Auth::can('billing.manage');

        return Inertia::render('billing/Index', [
            'filters' => $filters,
            'metrics' => [
                Present::metric('Outstanding', Format::money($totals['outstanding']), 'billing', (float) $totals['outstanding'] > 0 ? 'warning' : ''),
                Present::metric('Overdue', Format::money($totals['overdue']), 'billing', (float) $totals['overdue'] > 0 ? 'danger' : ''),
                Present::metric('Received this month', Format::money($totals['paid_month']), 'billing', 'success'),
                Present::metric('Invoices', (int) $totals['invoices'], 'billing'),
            ],
            'result' => [
                'total' => (int) $result['total'],
                'page' => (int) $result['page'],
                'pages' => (int) $result['pages'],
                'size' => (int) $result['size'],
                'rows' => array_map(static fn (array $r) => Invoices::row($r) + [
                    'customer' => Clients::displayName($r),
                    'service' => (string) (($r['service_name'] ?: $r['service_group']) ?: '-'),
                    'canEdit' => $manage && $r['status'] === 'draft',
                    // Invoices with payments cannot be voided (BillingRepository::voidInvoice), so the action is not offered.
                    'canVoid' => $manage && $r['status'] !== 'void' && (float) $r['amount_paid'] <= 0,
                    'canDelete' => $manage && $r['status'] === 'draft' && (float) $r['amount_paid'] <= 0,
                ], $result['rows']),
            ],
            'payments' => array_map(static fn (array $p) => [
                'id' => (int) $p['id'],
                'date' => (string) $p['payment_date'],
                'invoiceId' => (int) $p['invoice_id'],
                'invoice' => (string) $p['invoice_number'],
                'username' => (string) $p['username'],
                'amount' => Format::money($p['amount']) . ' ' . $p['currency'],
                'method' => self::PAYMENT_METHODS[$p['method']] ?? (string) $p['method'],
                'reference' => (string) ($p['reference'] ?: '-'),
            ], Db::select('SELECT p.*, i.invoice_number FROM billing_payments p JOIN billing_invoices i ON i.id=p.invoice_id ORDER BY p.payment_date DESC, p.id DESC LIMIT 8')),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->query();
        return Csv::download('invoices.csv', ['Invoice', 'Username', 'Customer', 'Service', 'Issued', 'Due', 'Total', 'Paid', 'Balance', 'Currency', 'Status'],
            static fn () => InvoiceQuery::each($filters),
            static fn (array $r) => [$r['invoice_number'], $r['username'], Clients::displayName($r), $r['service_name'] ?: $r['service_group'], $r['issue_date'], $r['due_date'], $r['total_amount'], $r['amount_paid'], $r['balance_due'], $r['currency'], $r['status']]);
    }

    public function generate(): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        $run = JobRunner::run('billing', static fn () => BillingRepository::generateRecurringInvoices());
        if ($run['state'] === 'skipped') {
            return $this->fail('Another lifecycle job is running; try again after completion.');
        }
        $result = $run['summary'];
        Audit::log('generate_recurring', 'billing_invoice', 'bulk', null, $result);
        return $this->ok('/billing', "Generated {$result['created']} invoice(s); skipped {$result['skipped']} existing or non-billable account(s).");
    }

    public function form(Request $request, ?string $id = null): Response|RedirectResponse
    {
        Auth::requireCan('billing.manage');
        $invoiceId = $id !== null ? (int) $id : null;
        $invoice = $invoiceId ? BillingRepository::invoice($invoiceId) : null;
        abort_if($invoiceId && !$invoice, 404, 'Invoice not found.');
        if ($invoice && $invoice['status'] !== 'draft') {
            return $this->fail('Issued invoices cannot be edited.', '/billing/invoices/' . $invoiceId);
        }
        $item = null;
        $staticItem = null;
        foreach ($invoiceId ? BillingRepository::invoiceItems($invoiceId) : [] as $line) {
            if (str_starts_with((string) $line['description'], 'Static IP')) {
                $staticItem = $line;
                continue;
            }
            $item ??= $line;
        }
        $prefill = null;
        if (!$invoice && is_string($request->query('username')) && $request->query('username') !== '') {
            $prefill = Db::row(
                'SELECT c.username, c.service_group, c.framed_ip_address, s.name AS service_name, s.price, s.currency
                 FROM customer_profiles c LEFT JOIN service_metadata s ON s.group_name=c.service_group
                 WHERE c.username=?',
                [$request->query('username')]
            );
        }
        $services = Db::select('SELECT group_name, name, price, currency FROM service_metadata WHERE enabled=1 ORDER BY name');
        $pools = Db::select('SELECT pool_key, name, ip_ranges, price, currency FROM static_ip_pools WHERE enabled=1 AND price > 0 ORDER BY name');

        $username = (string) ($invoice['username'] ?? $prefill['username'] ?? '');
        $staticIp = (string) ($invoice['framed_ip_address'] ?? $prefill['framed_ip_address'] ?? '');
        if ($staticIp === '' && $username !== '') {
            $staticIp = (string) (Db::value('SELECT framed_ip_address FROM customer_profiles WHERE username=?', [$username]) ?: '');
        }
        $parsedPools = array_map(static fn ($p) => $p + ['ips' => StaticIpPool::parse((string) $p['ip_ranges'])], $pools);
        $staticPool = '';
        if ($staticItem || (!$invoice && $staticIp !== '')) {
            foreach ($parsedPools as $pool) {
                if (in_array($staticIp, $pool['ips'], true)) {
                    $staticPool = (string) $pool['pool_key'];
                    break;
                }
            }
        }
        $serviceName = (string) (($prefill['service_name'] ?? '') ?: ($prefill['service_group'] ?? ''));
        $period = Invoices::period((string) ($invoice['billing_period'] ?? 'monthly'));
        $issueDate = (string) ($invoice['issue_date'] ?? date('Y-m-d'));
        $values = [
            'id' => $invoiceId ?? '',
            'username' => $username,
            'service_group' => (string) ($invoice['service_group'] ?? $prefill['service_group'] ?? ''),
            'static_ip_pool_key' => $staticPool,
            'billing_period' => $period,
            'description' => (string) ($item['description'] ?? ($serviceName !== '' ? $serviceName . ' service' : 'Internet service')),
            // Without a plan yet, the currency most plans use (not a fixed USD).
            'currency' => (string) ($invoice['currency'] ?? $prefill['currency'] ?? (Db::value('SELECT currency FROM service_metadata WHERE enabled=1 GROUP BY currency ORDER BY COUNT(*) DESC LIMIT 1') ?: 'USD')),
            'discount_amount' => (string) ($invoice['discount_amount'] ?? '0.00'),
            'tax_amount' => (string) ($invoice['tax_amount'] ?? '0.00'),
            'issue_date' => $issueDate,
            'status' => (string) ($invoice['status'] ?? 'open'),
            'notes' => (string) ($invoice['notes'] ?? ''),
        ];

        $option = static fn (string $value, string $name, mixed $price, string $currency) => [
            'value' => $value, 'name' => $name, 'price' => (float) $price, 'currency' => $currency,
            'label' => $name . ' - ' . Format::money($price) . ' ' . $currency,
        ];
        return Inertia::render('billing/Form', [
            'values' => $values,
            'customers' => array_map(static fn ($c) => ['value' => (string) $c['username'], 'label' => Clients::displayName($c) . ' (' . $c['username'] . ')'],
                Db::select('SELECT username, first_name, last_name, company_name FROM customer_profiles WHERE account_status <> "archived" ORDER BY username')),
            'services' => array_map(static fn ($s) => $option((string) $s['group_name'], (string) ($s['name'] ?: $s['group_name']), $s['price'], (string) $s['currency']), $services),
            'staticPools' => array_map(static fn ($p) => $option((string) $p['pool_key'], (string) ($p['name'] ?: $p['pool_key']), $p['price'], (string) $p['currency']), $pools),
            'periods' => Invoices::PERIODS,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        $input = $this->formInput($request);
        unset($input['_cycle_key'], $input['anchor_day'], $input['_period_end'], $input['_replaces_invoice_id'], $input['_return_to'], $input['_active_tab']);
        $id = !empty($input['id']) ? (int) $input['id'] : null;
        $before = $id ? BillingRepository::invoice($id) : null;
        $formPath = $id ? '/billing/invoices/' . $id . '/edit' : '/billing/invoices/create';
        try {
            $saved = BillingRepository::saveInvoice($input, $id);
        } catch (Throwable $e) {
            return $this->failKeepingInput($e, $formPath . ($id ? '' : $this->usernameQuery($input)), self::FIELD_ERRORS);
        }
        try {
            Audit::log($id ? 'update' : 'create', 'billing_invoice', (string) $saved, $before, $input);
        } catch (Throwable $e) {
            return $this->warn('/billing/invoices/' . $saved, 'Invoice saved. Its audit entry could not be recorded. Do not resubmit the invoice. Reference: ' . Diagnostics::report($e));
        }
        return $this->ok('/billing/invoices/' . $saved, 'Invoice saved.');
    }

    private function usernameQuery(array $input): string
    {
        $username = (string) ($input['username'] ?? '');
        return $username !== '' ? '?username=' . rawurlencode($username) : '';
    }

    public function show(Request $request, string $id): Response
    {
        $invoice = BillingRepository::invoice((int) $id);
        abort_if(!$invoice, 404, 'Invoice not found.');
        $invoiceId = (int) $invoice['id'];
        $items = BillingRepository::invoiceItems($invoiceId);
        $payments = BillingRepository::invoicePayments($invoiceId);
        $manage = Auth::can('billing.manage');
        $currency = ' ' . $invoice['currency'];
        $period = Invoices::periodLabel((string) ($invoice['billing_period'] ?? 'monthly'));

        $payment = null;
        if ($manage && !in_array($invoice['status'], ['paid', 'void', 'draft'], true)) {
            $intent = $this->paymentIntent($request, $invoiceId);
            $recorded = Db::row('SELECT id, amount, currency FROM billing_payments WHERE request_key=? AND invoice_id=?', [$intent['key'], $invoiceId]);
            $payment = $recorded
                ? ['recorded' => ['id' => (int) $recorded['id'], 'amount' => Format::money($recorded['amount']) . ' ' . $recorded['currency']], 'key' => $intent['key']]
                : ['recorded' => null, 'key' => $intent['key'], 'values' => [
                    'payment_date' => (string) ($intent['input']['payment_date'] ?? Entitlement::today()),
                    'amount' => (string) ($intent['input']['amount'] ?? $invoice['balance_due']),
                    'method' => (string) ($intent['input']['method'] ?? 'cash'),
                    'reference' => (string) ($intent['input']['reference'] ?? ''),
                    'notes' => (string) ($intent['input']['notes'] ?? ''),
                ]];
        }

        $classify = null;
        if ($manage && ($invoice['cycle_class'] === null || $invoice['billing_anchor_day'] === null)) {
            $classify = !empty($invoice['cycle_key']) ? ['generated' => 'generated subscription cycle']
                : ($invoice['cycle_class'] !== null ? [$invoice['cycle_class'] => $invoice['cycle_class']] : ['manual' => 'manual invoice', 'generated' => 'generated subscription cycle']);
        }

        return Inertia::render('billing/Show', [
            'invoice' => [
                'id' => $invoiceId,
                'number' => (string) $invoice['invoice_number'],
                'username' => (string) $invoice['username'],
                'notes' => (string) ($invoice['notes'] ?? ''),
                'status' => Invoices::status($invoice),
                'banner' => Invoices::banner($invoice, $payments),
                'summary' => [
                    ['Invoice', (string) $invoice['invoice_number']],
                    ['Customer', Clients::displayName($invoice)],
                    ['Issued', (string) $invoice['issue_date']],
                    ['Due', (string) $invoice['due_date']],
                    ['Billing period', $period],
                    ['Total', Format::money($invoice['total_amount']) . $currency],
                    ['Amount paid', Format::money($invoice['amount_paid']) . $currency],
                    ['Balance due', Format::money($invoice['balance_due']) . $currency],
                ],
                'draftPdf' => $invoice['status'] !== 'void',
                'receipt' => Invoices::receipt($invoice),
            ],
            'items' => array_map(static fn ($i) => ['description' => (string) $i['description'], 'period' => $period, 'total' => Format::money($i['line_total'])], $items),
            'payments' => array_map(static fn ($p) => [
                'id' => (int) $p['id'],
                'date' => (string) $p['payment_date'],
                'amount' => Format::money($p['amount']) . ' ' . $p['currency'],
                'method' => self::PAYMENT_METHODS[$p['method']] ?? (string) $p['method'],
                'reference' => (string) ($p['reference'] ?: '-'),
                'receiver' => (string) (($p['receiver'] ?? '') ?: 'system'),
                'notes' => (string) ($p['notes'] ?: '-'),
            ], $payments),
            'payment' => $payment,
            'methods' => self::PAYMENT_METHODS,
            'canEdit' => $manage && $invoice['status'] === 'draft',
            'canVoid' => $manage && $invoice['status'] !== 'void' && !$payments && (float) $invoice['amount_paid'] <= 0,
            'canDelete' => $manage && $invoice['status'] === 'draft' && !$payments && (float) $invoice['amount_paid'] <= 0,
            'classify' => $classify,
            // Set once by paymentSave(): the receipt the browser downloads after a payment is recorded.
            'download' => $request->session()->get('download'),
        ]);
    }

    /** One idempotency key per invoice and session, so a double-submitted payment is recorded once. */
    private function paymentIntent(Request $request, int $invoiceId): array
    {
        $key = 'billing_payment_intents.' . $invoiceId;
        if (!$request->session()->has($key)) {
            $request->session()->put($key, ['key' => bin2hex(random_bytes(24))]);
        }
        return $request->session()->get($key);
    }

    public function paymentSave(Request $request, string $id): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        $invoiceId = (int) $id;
        $input = $this->formInput($request);
        $key = (string) ($input['request_key'] ?? '');
        try {
            $intent = $this->paymentIntent($request, $invoiceId);
            if (!hash_equals($intent['key'], $key) && !Db::row('SELECT id FROM billing_payments WHERE invoice_id=? AND request_key=?', [$invoiceId, $key])) {
                throw new RuntimeException('Payment form changed. Review recorded payments and reload before continuing.');
            }
            if ($intent['key'] === $key) {
                $request->session()->put('billing_payment_intents.' . $invoiceId . '.input', array_intersect_key($input, array_flip(['amount', 'payment_date', 'method', 'reference', 'notes'])));
            }
            BillingRepository::recordPayment($invoiceId, $input);
        } catch (Throwable $e) {
            $message = self::operatorErrorMessage($e);
            if ($message === null) {
                throw $e;
            }
            return $this->fail($message, '/billing/invoices/' . $invoiceId);
        }
        return redirect('/billing/invoices/' . $invoiceId)
            ->with('download', '/billing/invoices/' . $invoiceId . '/payment-pdf')
            ->with('flash', ['type' => 'success', 'message' => 'Payment recorded. The receipt is downloading.']);
    }

    public function paymentStart(Request $request, string $id): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        $invoiceId = (int) $id;
        $intent = $this->paymentIntent($request, $invoiceId);
        if (!hash_equals($intent['key'], (string) $request->input('previous_request_key', ''))
            || !Db::row('SELECT id FROM billing_payments WHERE invoice_id=? AND request_key=?', [$invoiceId, $intent['key']])) {
            return $this->fail('Confirm the recorded payment before starting another payment.');
        }
        $request->session()->put('billing_payment_intents.' . $invoiceId, ['key' => bin2hex(random_bytes(24))]);
        return redirect('/billing/invoices/' . $invoiceId);
    }

    public function classify(Request $request, string $id): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        try {
            $anchor = trim((string) $request->input('anchor_day', ''));
            if ($anchor !== '' && !preg_match('/^(?:[1-9]|[12][0-9]|3[01])$/D', $anchor)) {
                throw new RuntimeException('Invalid sold anchor.');
            }
            BillingRepository::classifyLegacyInvoice((int) $id, (string) $request->input('classification', ''), (string) $request->input('evidence', ''), $anchor === '' ? null : (int) $anchor);
        } catch (Throwable $e) {
            return $this->fail(self::operatorErrorMessage($e) ?? throw $e);
        }
        return $this->ok('/billing/invoices/' . (int) $id, 'Legacy invoice classification recorded.');
    }

    public function void(string $id): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        try {
            $before = BillingRepository::invoice((int) $id);
            BillingRepository::voidInvoice((int) $id);
            Audit::log('void', 'billing_invoice', $id, $before);
        } catch (Throwable $e) {
            return $this->fail(self::operatorErrorMessage($e) ?? throw $e);
        }
        return $this->ok('/billing/invoices/' . (int) $id, 'Invoice cancelled.');
    }

    public function delete(string $id): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        $before = BillingRepository::invoice((int) $id);
        if (!$before) {
            return $this->fail('Invoice not found.');
        }
        try {
            BillingRepository::deleteInvoice((int) $id);
            Audit::log('delete', 'billing_invoice', $id, $before);
        } catch (Throwable $e) {
            return $this->fail(self::operatorErrorMessage($e) ?? throw $e);
        }
        return $this->ok('/billing', 'Invoice deleted.');
    }

    public function draftPdf(string $id): HttpResponse
    {
        $invoice = BillingRepository::invoice((int) $id);
        abort_if(!$invoice, 404, 'Invoice not found.');
        $pdf = Invoices::pdf('DRAFT PAYMENT REQUEST', $invoice, BillingRepository::invoiceItems((int) $id), [], 'This document is a payment request, not a receipt.');
        return $this->pdfDownload('draft-payment-' . $invoice['invoice_number'] . '.pdf', $pdf);
    }

    public function paymentPdf(string $id): HttpResponse|RedirectResponse
    {
        $invoice = BillingRepository::invoice((int) $id);
        abort_if(!$invoice, 404, 'Invoice not found.');
        $payments = BillingRepository::invoicePayments((int) $id);
        try {
            $receipt = BillingRepository::receiptSummary($invoice, $payments);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), '/billing/invoices/' . (int) $id);
        }
        $invoice['amount_paid'] = $receipt['amount_paid'];
        $invoice['balance_due'] = $receipt['balance_due'];
        $invoice['status'] = $receipt['state'];
        $pdf = Invoices::pdf($receipt['title'], $invoice, BillingRepository::invoiceItems((int) $id), $payments, 'Confirms recorded payments only; see amount paid and remaining balance.', $receipt['state']);
        return $this->pdfDownload('payment-' . $invoice['invoice_number'] . '.pdf', $pdf);
    }

    private function pdfDownload(string $filename, string $pdf): HttpResponse
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', '\\', "\r", "\n"], '', $filename) . '"',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
