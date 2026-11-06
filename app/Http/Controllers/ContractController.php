<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Clients;
use App\Support\Auth;
use App\Support\Contracts;
use App\Support\Db;
use App\Support\Entitlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Customer contracts (RadiusOne Pro): the list, issuing one with its first invoice, the document, and cancelling. */
final class ContractController extends Controller
{
    public function index(Request $request): Response
    {
        Contracts::endExpired();
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(x.username LIKE ? OR x.contract_number LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?)';
            array_push($params, ...array_fill(0, 5, '%' . addcslashes($q, '%_\\') . '%'));
        }
        if (in_array($status, ['active', 'ended', 'cancelled'], true)) {
            $where[] = 'x.status=?';
            $params[] = $status;
        }
        $rows = Db::select('SELECT x.*, c.first_name, c.last_name, c.company_name FROM customer_contracts x JOIN customer_profiles c ON c.username=x.username '
            . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY x.id DESC LIMIT 500', $params);
        return Inertia::render('contracts/Index', [
            'filters' => ['q' => $q, 'status' => $status],
            'rows' => array_map(fn (array $r) => $this->row($r) + ['customer' => Clients::displayName($r)], $rows),
            'canManage' => Auth::can('billing.manage'),
        ]);
    }

    public function form(Request $request): Response
    {
        Auth::requireCan('billing.manage');
        return Inertia::render('contracts/Form', [
            'values' => ['username' => (string) $request->query('username', ''), 'term_months' => '12', 'billing_period' => 'monthly', 'start_date' => Entitlement::today(), 'issue_invoice' => true],
            'periods' => ['monthly' => '1 month', '3_months' => '3 months', '6_months' => '6 months', 'yearly' => '12 months'],
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        try {
            $id = Contracts::create($this->formInput($request));
        } catch (RuntimeException $e) {
            return $this->failKeepingInput($e, '/contracts/create');
        }
        return $this->ok("/contracts/{$id}", 'Contract issued.');
    }

    public function show(string $id): Response
    {
        $contract = Db::row('SELECT x.*, c.first_name, c.last_name, c.company_name FROM customer_contracts x JOIN customer_profiles c ON c.username=x.username WHERE x.id=?', [(int) $id]);
        abort_if(!$contract, 404, 'Contract not found.');
        return Inertia::render('contracts/Show', [
            'contract' => $this->row($contract) + ['customer' => Clients::displayName($contract), 'body' => (string) $contract['body'], 'firstInvoiceId' => $contract['first_invoice_id'] ? (int) $contract['first_invoice_id'] : null],
            'payments' => array_map(static fn (array $p) => [
                'id' => (int) $p['id'], 'provider' => \App\Support\Pay\Gateways::label((string) $p['provider'], (string) $p['mode']), 'status' => (string) $p['status'], 'amount' => (string) $p['amount'], 'currency' => (string) $p['currency'], 'period_end' => (string) $p['period_end'],
                'created_at' => (string) $p['created_at'], 'note' => (string) ($p['note'] ?? ''), 'invoice_id' => $p['invoice_id'] ? (int) $p['invoice_id'] : null,
            ], Db::select('SELECT * FROM pay_payments WHERE contract_id=? ORDER BY id DESC LIMIT 50', [(int) $id])),
            'canManage' => Auth::can('billing.manage'),
        ]);
    }

    public function pdf(string $id): HttpResponse
    {
        $contract = Db::row('SELECT * FROM customer_contracts WHERE id=?', [(int) $id]);
        abort_if(!$contract, 404, 'Contract not found.');
        return response(Contracts::pdf($contract), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', (string) $contract['contract_number']) . '.pdf"',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    public function cancel(string $id): RedirectResponse
    {
        Auth::requireCan('billing.manage');
        try {
            Contracts::cancel((int) $id);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), '/contracts/' . (int) $id);
        }
        return $this->ok('/contracts/' . (int) $id, 'Contract cancelled. The customer can no longer pay on the pay page.');
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function row(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'number' => (string) $r['contract_number'], 'username' => (string) $r['username'], 'service' => (string) $r['service_name'],
            'monthlyPrice' => (string) $r['monthly_price'], 'currency' => (string) $r['currency'], 'period' => Contracts::periodLabel((string) $r['billing_period']),
            'start' => (string) $r['start_date'], 'end' => (string) $r['end_date'], 'term' => (int) $r['term_months'], 'status' => (string) $r['status'],
        ];
    }
}
