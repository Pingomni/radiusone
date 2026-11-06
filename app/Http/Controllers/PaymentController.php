<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Clients;
use App\Http\Support\Csv;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\Currency;
use App\Support\Db;
use App\Support\Entitlement;
use App\Support\Pay\Gateways;
use App\Support\PayPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Online payments for operators (RadiusOne Pro): what customers paid on the pay page, what is open, what needs a person. */
final class PaymentController extends Controller
{
    private const PER_PAGE = 50;

    /** Status tab => the statuses in it. "Not completed" gathers the ways a payment ends without money. */
    private const TABS = [
        'paid' => ['paid'], 'pending' => ['pending'], 'mismatch' => ['mismatch'], 'reviewed' => ['reviewed'], 'closed' => ['canceled', 'expired', 'failed'],
    ];

    private const SELECT = 'SELECT p.*, c.first_name, c.last_name, c.company_name, i.invoice_number, x.contract_number FROM pay_payments p
        JOIN customer_profiles c ON c.username=p.username
        LEFT JOIN billing_invoices i ON i.id=p.invoice_id
        LEFT JOIN customer_contracts x ON x.id=p.contract_id';

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        [$where, $params] = $this->where($filters);
        $page = max(1, (int) $request->query('page', 1));
        $total = (int) Db::value('SELECT COUNT(*) FROM pay_payments p JOIN customer_profiles c ON c.username=p.username LEFT JOIN billing_invoices i ON i.id=p.invoice_id ' . $where, $params);
        $rows = Db::select(self::SELECT . " {$where} ORDER BY p.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        $counts = [];
        foreach (self::TABS as $tab => $statuses) {
            $counts[$tab] = (int) Db::value('SELECT COUNT(*) FROM pay_payments WHERE status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')', $statuses);
        }
        $today = Entitlement::today();
        return Inertia::render('payments/Index', [
            'filters' => $filters,
            'providers' => Gateways::NAMES,
            'counts' => $counts + ['all' => (int) Db::value('SELECT COUNT(*) FROM pay_payments')],
            'metrics' => [
                ['label' => 'Paid today', 'value' => $this->sum('DATE(paid_at)=?', [$today]), 'icon' => 'billing', 'tone' => '', 'href' => '/payments?status=paid&from=' . $today . '&to=' . $today],
                ['label' => 'Paid this month', 'value' => $this->sum('paid_at>=?', [substr($today, 0, 8) . '01']), 'icon' => 'reports', 'tone' => '', 'href' => '/payments?status=paid&from=' . substr($today, 0, 8) . '01'],
                ['label' => 'Waiting for payment', 'value' => (string) $counts['pending'], 'icon' => 'refresh', 'tone' => '', 'href' => '/payments?status=pending'],
                ['label' => 'Needs review', 'value' => (string) $counts['mismatch'], 'icon' => 'shield', 'tone' => $counts['mismatch'] ? 'danger' : '', 'href' => '/payments?status=mismatch'],
            ],
            'result' => [
                'rows' => array_map(fn (array $r) => $this->row($r), $rows),
                'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PER_PAGE)), 'size' => self::PER_PAGE,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$where, $params] = $this->where($this->filters($request));
        return Csv::download('online-payments.csv', ['Started', 'Paid', 'Username', 'Customer', 'Service', 'Status', 'Amount', 'Currency', 'Covers from', 'Covers until', 'Invoice', 'Order', 'Note'],
            static fn () => Db::select(self::SELECT . " {$where} ORDER BY p.id DESC LIMIT 50000", $params),
            static fn (array $r) => [$r['created_at'], $r['paid_at'], $r['username'], Clients::displayName($r), Gateways::label((string) $r['provider'], (string) $r['mode']), $r['status'], $r['amount'], $r['currency'], $r['period_start'], $r['period_end'], $r['invoice_number'], $r['provider_order_id'], $r['note']]);
    }

    public function show(string $id): Response
    {
        $payment = Db::row(self::SELECT . ' WHERE p.id=?', [(int) $id]);
        abort_if(!$payment, 404, 'Payment not found.');
        $reviewer = $payment['reviewed_by'] ? Db::value('SELECT username FROM portal_users WHERE id=?', [$payment['reviewed_by']]) : null;
        return Inertia::render('payments/Show', [
            'payment' => $this->row($payment) + [
                'order' => (string) ($payment['provider_order_id'] ?? ''),
                'contractId' => (int) $payment['contract_id'],
                'contract' => (string) ($payment['contract_number'] ?? ''),
                'expiresAt' => (string) ($payment['expires_at'] ?? ''),
                'reviewedAt' => (string) ($payment['reviewed_at'] ?? ''),
                'reviewedBy' => $reviewer ? (string) $reviewer : '',
                'reviewNote' => (string) ($payment['review_note'] ?? ''),
            ],
            'canManage' => Auth::can('billing.manage'),
        ]);
    }

    /** Asks the service now, for a payment still open. What the service says decides, as always. */
    public function check(string $id): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        $payment = Db::row('SELECT * FROM pay_payments WHERE id=?', [(int) $id]);
        abort_if(!$payment, 404, 'Payment not found.');
        $back = '/payments/' . (int) $id;
        if ($payment['status'] !== 'pending') {
            return $this->fail('Only a payment still waiting can be checked.', $back);
        }
        try {
            $state = PayPage::sync($payment);
        } catch (Throwable $e) {
            return $this->fail('Could not check with the service: ' . $e->getMessage(), $back);
        }
        return match ($state) {
            'paid' => $this->ok($back, 'The service confirmed the payment: the invoice is paid and the service renewed.'),
            'waiting' => $this->warn($back, 'The service has not received this payment yet.'),
            'mismatch' => $this->warn($back, 'This payment needs review: see the note.'),
            default => $this->warn($back, 'The service says this payment did not complete.'),
        };
    }

    /** Closes a payment that needed a person, once they have dealt with it at the service and in Billing. */
    public function review(Request $request, string $id): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        $back = '/payments/' . (int) $id;
        $note = trim((string) ($this->formInput($request)['note'] ?? ''));
        if ($note === '' || mb_strlen($note) > 255) {
            return $this->fail('Write what was done, in at most 255 characters.', $back);
        }
        $payment = Db::row('SELECT status FROM pay_payments WHERE id=?', [(int) $id]);
        abort_if(!$payment, 404, 'Payment not found.');
        if ($payment['status'] !== 'mismatch') {
            return $this->fail('Only a payment that needs review can be closed this way.', $back);
        }
        Db::exec('UPDATE pay_payments SET status="reviewed", reviewed_by=?, reviewed_at=NOW(), review_note=?, updated_at=NOW() WHERE id=? AND status="mismatch"', [Auth::user()['id'] ?? null, $note, (int) $id]);
        Audit::log('review', 'pay_page', (string) (int) $id, ['status' => 'mismatch'], ['status' => 'reviewed', 'note' => $note]);
        return $this->ok($back, 'Marked as reviewed.');
    }

    /** @return array{q: string, status: string, provider: string, from: string, to: string} */
    private function filters(Request $request): array
    {
        $date = static fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $request->query($key, '')) ? (string) $request->query($key) : '';
        $status = (string) $request->query('status', '');
        $provider = (string) $request->query('provider', '');
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => isset(self::TABS[$status]) ? $status : '',
            'provider' => isset(Gateways::NAMES[$provider]) ? $provider : '',
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    /**
     * @param  array{q: string, status: string, provider: string, from: string, to: string}  $f
     * @return array{0: string, 1: list<string>}
     */
    private function where(array $f): array
    {
        $where = [];
        $params = [];
        if ($f['q'] !== '') {
            $where[] = '(p.username LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ? OR p.provider_order_id LIKE ? OR i.invoice_number LIKE ?)';
            array_push($params, ...array_fill(0, 6, '%' . addcslashes($f['q'], '%_\\') . '%'));
        }
        if ($f['status'] !== '') {
            $statuses = self::TABS[$f['status']];
            $where[] = 'p.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        if ($f['provider'] !== '') {
            $where[] = 'p.provider=?';
            $params[] = $f['provider'];
        }
        if ($f['from'] !== '') {
            $where[] = 'p.created_at>=?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== '') {
            $where[] = 'p.created_at<=?';
            $params[] = $f['to'] . ' 23:59:59';
        }
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * What was paid, by currency: amounts in different currencies are never added together.
     *
     * @param list<string> $params
     */
    private function sum(string $condition, array $params): string
    {
        $rows = Db::select("SELECT currency, SUM(amount) AS total FROM pay_payments WHERE status='paid' AND mode='live' AND {$condition} GROUP BY currency", $params);
        if (!$rows) {
            return '0.00 ' . Currency::code();
        }
        return implode(' + ', array_map(static fn (array $r) => number_format((float) $r['total'], 2, '.', ',') . ' ' . $r['currency'], $rows));
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function row(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'username' => (string) $r['username'], 'customer' => Clients::displayName($r), 'provider' => Gateways::label((string) $r['provider'], (string) $r['mode']),
            'status' => (string) $r['status'], 'amount' => (string) $r['amount'], 'currency' => (string) $r['currency'],
            'periodStart' => (string) $r['period_start'], 'periodEnd' => (string) $r['period_end'], 'billingPeriod' => (string) $r['billing_period'],
            'createdAt' => (string) $r['created_at'], 'paidAt' => (string) ($r['paid_at'] ?? ''), 'note' => (string) ($r['note'] ?? ''),
            'invoiceId' => $r['invoice_id'] ? (int) $r['invoice_id'] : null, 'invoice' => (string) ($r['invoice_number'] ?? ''),
        ];
    }
}
