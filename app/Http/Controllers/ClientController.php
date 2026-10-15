<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Clients;
use App\Http\Support\Csv;
use App\Http\Support\Format;
use App\Http\Support\FormDrafts;
use App\Http\Support\Invoices;
use App\Http\Support\Present;
use App\Support\AccessPolicy;
use App\Support\Accounting;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\ClientQuery;
use App\Support\ConnectionDiagnostics;
use App\Support\Db;
use App\Support\Diagnostics;
use App\Support\Documents;
use App\Support\NetworkOperations;
use App\Support\RadiusRepository;
use App\Support\SchemaCompatibility;
use App\Support\SubscriberPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Clients: list and export, profile form and save, overview, bulk actions, documents, disconnect, suspension. */
final class ClientController extends Controller
{
    private const TABS = ['account', 'personal', 'accounting', 'billing', 'documents', 'logs'];
    private const FILTERS = ['q', 'plan', 'nas', 'status'];

    /** Field-level placement of RadiusRepository's validation messages. */
    private const FIELD_ERRORS = [
        'mac_address' => '/^(MAC address is required|Enter a valid MAC address)/',
        'username' => '/^(Username is required|Username already exists|Username is reserved|Identity reference migration)/',
        'password' => '/^PPPoE password is required/',
        'expiry_date' => '/^Invalid expiry date/',
        'service_group' => '/^Select an enabled service plan/',
        'access_profile_id' => '/^(Access profile|Select a supported NAS)/',
        'dhcp_client_id' => '/^Invalid DHCP client/',
        'circuit_id' => '/^Invalid access-circuit/',
        'framed_ip_address' => '/^(Select a valid IPv4|Selected static IP|Static IP .* is already assigned)/',
        'contract_valid_until' => '/contract/i',
    ];

    public function index(Request $request): Response
    {
        $filters = array_map(static fn ($v) => is_scalar($v) ? trim((string) $v) : '', $request->only(self::FILTERS)) + array_fill_keys(self::FILTERS, '');
        $result = ClientQuery::page($request->query(), (int) $request->query('page', 1));
        return Inertia::render('clients/Index', [
            'filters' => $filters,
            'plans' => array_map(static fn ($p) => ['value' => (string) $p['group_name'], 'label' => (string) ($p['name'] ?: $p['group_name'])], Db::select('SELECT group_name, name FROM service_metadata ORDER BY name')),
            'nasOptions' => array_map(static fn ($n) => ['value' => (string) $n['nasname'], 'label' => trim(($n['shortname'] ?: 'NAS') . ' - ' . $n['nasname'], ' -')], Db::select('SELECT nasname, shortname FROM nas ORDER BY shortname, nasname')),
            'result' => [
                'total' => (int) $result['total'],
                'page' => (int) $result['page'],
                'pages' => (int) $result['pages'],
                'size' => (int) $result['size'],
                'rows' => array_map(static fn (array $r) => [
                    'username' => (string) $r['username'],
                    'auth' => Clients::authTag($r),
                    'customer' => (trim($r['first_name'] . ' ' . $r['last_name']) ?: (string) $r['company_name']) ?: '—',
                    'phone' => (string) $r['phone'],
                    'plan' => (string) ($r['service_name'] ?: $r['service_group']),
                    'status' => Clients::statusTag($r),
                    'connection' => Present::tag(Clients::accountingLabel($r), $r['online_count'] ? 'success' : ($r['stale_count'] ? 'warning' : '')),
                    'ip' => (string) ($r['online_ip'] ?: $r['framed_ip_address']),
                    'nas' => (string) $r['online_nas'],
                    'mac' => (string) $r['mac_address'],
                    'online' => (bool) $r['online_ip'],
                    'traffic' => Format::bytes((int) $r['down'] + (int) $r['up']),
                    'expiry' => (string) ($r['expiry_date'] ?: '-'),
                    'balance' => Format::money($r['balance']),
                    'archived' => Clients::isArchived($r),
                ], $result['rows']),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->query();
        return Csv::download('customers.csv', ['Username', 'Customer', 'Plan', 'Account status', 'Connection', 'Session counter total (bytes)', 'Expiry'],
            static fn () => ClientQuery::each($filters),
            static fn (array $r) => [$r['username'], trim($r['first_name'] . ' ' . $r['last_name']) ?: $r['company_name'], $r['service_name'] ?: $r['service_group'], Clients::statusLabel($r), Clients::accountingLabel($r), (string) ((int) $r['up'] + (int) $r['down']), $r['expiry_date']]);
    }

    public function form(Request $request, ?string $username = null): Response
    {
        $row = $username !== null ? Db::row('SELECT * FROM customer_profiles WHERE username = ?', [$username]) : null;
        // Without this, an unknown username rendered an empty "new client" form.
        abort_if($username !== null && !$row, 404, 'Client ' . $username . ' was not found. It may have been renamed.');
        $formPath = $username !== null ? '/clients/' . rawurlencode($username) . '/edit' : '/clients/create';
        $plans = Db::select('SELECT group_name, name FROM service_metadata WHERE enabled=1 ORDER BY name');
        $planNames = array_column($plans, 'name', 'group_name');
        $binding = Clients::binding($row);
        $values = [
            'old_username' => (string) ($row['username'] ?? ''),
            'auth_method' => (string) ($row['auth_method'] ?? 'pppoe'),
            'username' => (string) ($row['username'] ?? ''),
            'password' => '',
            'verified' => !empty($row['verified']) ? '1' : '0',
            'account_status' => Clients::statusFormValue($row),
            'expiry_date' => (string) ($row['expiry_date'] ?? ''),
            'service_group' => (string) ($row['service_group'] ?? ''),
            'framed_ip_address' => (string) ($row['framed_ip_address'] ?? ''),
            'framed_pool' => (string) ($row['framed_pool'] ?? ''),
            'email_alert' => (bool) ($row['email_alert'] ?? false),
            'sms_alert' => (bool) ($row['sms_alert'] ?? false),
            'access_profile_id' => $binding['access_profile_id'] ?? '',
            'dhcp_client_id' => $binding['dhcp_client_id'] ?? '',
            'circuit_id' => $binding['circuit_id'] ?? '',
            'mac_address' => (string) ($row['mac_address'] ?? ''),
            'mac_lock' => (bool) ($row['mac_lock'] ?? false),
            'nas_restriction_type' => (string) ($row['nas_restriction_type'] ?? 'all'),
            'nas_restriction_value' => (string) ($row['nas_restriction_value'] ?? ''),
            'language' => (string) ($row['language'] ?? 'en'),
        ];
        foreach (['first_name', 'last_name', 'company_name', 'vat_tax_id', 'phone', 'cell_phone', 'email', 'address', 'city', 'state', 'zip', 'country', 'gps', 'contract_id', 'contract_valid_until', 'notes'] as $field) {
            $values[$field] = (string) ($row[$field] ?? '');
        }
        $draft = FormDrafts::take('client', $formPath);
        if ($draft) {
            $values = array_replace($values, $draft['values']);
        }
        $canEdit = Auth::can('clients.manage') && !Clients::isArchived($row);
        $props = [
            'values' => $values,
            'draftRestored' => $draft !== null,
            'draftTab' => $draft['tab'] ?? null,
            'canEdit' => $canEdit,
            'canUpload' => $canEdit && Auth::can('documents.manage'),
            'plans' => $planNames,
            'staticIps' => Clients::staticIpOptions($row),
            'binding' => $binding ? ['options' => $binding['options']] : null,
            'client' => null,
        ];
        if (!$row) {
            return Inertia::render('clients/Form', $props);
        }

        $ids = ClientQuery::usernames((string) $username);
        $in = ClientQuery::inUsernames('username', $ids);
        $invoices = Db::select('SELECT * FROM billing_invoices WHERE username=? ORDER BY created_at DESC LIMIT 50', [$username]);
        $openInvoices = count(array_filter($invoices, static fn ($i) => in_array($i['status'] ?? '', ['open', 'partial', 'overdue'], true) && (float) ($i['balance_due'] ?? 0) > 0));
        $props['client'] = [
            'username' => (string) $row['username'],
            'header' => Clients::header($row, $planNames, $openInvoices),
            'overview' => Clients::overview($row),
            'weeklyTraffic' => array_reverse(array_map(static fn (array $r) => ['day' => (string) $r['traffic_time'], 'down' => (int) $r['down'], 'up' => (int) $r['up']], Db::select(
                'SELECT DATE(COALESCE(acctupdatetime, acctstoptime, acctstarttime)) AS traffic_time, COALESCE(SUM(acctoutputoctets), 0) AS down, COALESCE(SUM(acctinputoctets), 0) AS up
                 FROM radacct WHERE ' . $in . ' AND COALESCE(acctupdatetime, acctstoptime, acctstarttime) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                 GROUP BY DATE(COALESCE(acctupdatetime, acctstoptime, acctstarttime)) ORDER BY traffic_time DESC', $ids))),
            'sessions' => array_map(static fn (array $r) => [
                'start' => (string) $r['acctstarttime'],
                'stop' => (string) ($r['acctstoptime'] ?? ''),
                'state' => Accounting::state($r),
                'time' => Format::seconds((int) $r['acctsessiontime']),
                'ip' => (string) $r['framedipaddress'],
                'nas' => (string) $r['nasipaddress'],
                'down' => Format::bytes(Format::acctDownload($r)),
                'up' => Format::bytes(Format::acctUpload($r)),
                'cause' => (string) $r['acctterminatecause'],
            ], Db::select('SELECT acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, framedipaddress, nasipaddress, acctinputoctets, acctoutputoctets, acctterminatecause FROM radacct WHERE ' . $in . ' ORDER BY acctstarttime DESC LIMIT 50', $ids)),
            'billing' => $this->billing($row, $invoices),
            'documents' => Auth::can('documents.view') ? array_map(static fn (array $d) => [
                'id' => (int) $d['id'],
                'type' => (string) $d['document_type'],
                'name' => (string) $d['original_name'],
                'mime' => (string) $d['mime_type'],
                'uploaded' => (string) $d['created_at'],
            ], Db::select('SELECT * FROM uploaded_documents WHERE username=? ORDER BY created_at DESC', [$username])) : [],
            'logs' => array_map(static fn (array $r) => [
                'actor' => (string) $r['actor_username'],
                'action' => (string) $r['action'],
                'entity' => $r['entity_type'] . ':' . $r['entity_id'],
                'time' => (string) $r['created_at'],
            ], Db::select('SELECT * FROM audit_logs WHERE entity_id=? OR after_json LIKE ? ORDER BY created_at DESC LIMIT 50', [$username, '%"username":"' . $username . '"%'])),
            'revealed' => $request->session()->get('revealed_password'),
        ];
        return Inertia::render('clients/Form', $props);
    }

    private function billing(array $client, array $invoices): array
    {
        $totals = Db::row(
            'SELECT
                COALESCE(SUM(CASE WHEN status NOT IN ("draft","void") THEN total_amount ELSE 0 END),0) AS billed,
                COALESCE(SUM(CASE WHEN status NOT IN ("draft","void") THEN amount_paid ELSE 0 END),0) AS paid,
                COALESCE(SUM(CASE WHEN status IN ("open","partial","overdue") THEN balance_due ELSE 0 END),0) AS outstanding,
                COALESCE(SUM(CASE WHEN status = "overdue" THEN balance_due ELSE 0 END),0) AS overdue,
                SUM(status IN ("open","partial","overdue")) AS unpaid_count,
                SUM(status = "draft") AS draft_count,
                MAX(currency) AS currency
             FROM billing_invoices WHERE username=?',
            [$client['username']]
        ) ?: [];
        $currency = (string) (($totals['currency'] ?? '') ?: '');
        $unpaid = (int) ($totals['unpaid_count'] ?? 0);
        $drafts = (int) ($totals['draft_count'] ?? 0);
        $overdue = (float) ($totals['overdue'] ?? 0);
        $stat = static fn (string $label, string $value, string $note, string $tone = '') => ['label' => $label, 'value' => trim($value), 'note' => $note, 'tone' => $tone];
        return [
            'newInvoice' => Auth::can('billing.manage') && !Clients::isArchived($client),
            'stats' => [
                $stat('Total billed', Format::money($totals['billed'] ?? 0) . ' ' . $currency, 'Issued invoices only'),
                $stat('Paid', Format::money($totals['paid'] ?? 0) . ' ' . $currency, 'Payments received', 'success'),
                $stat('Still owed', Format::money($totals['outstanding'] ?? 0) . ' ' . $currency, $unpaid ? $unpaid . ' unpaid invoice' . ($unpaid === 1 ? '' : 's') : 'Nothing owed', $overdue > 0 ? 'danger' : ($unpaid ? 'warning' : '')),
                $stat('Drafts', (string) $drafts, $drafts ? 'Not billed to the customer yet' : 'No drafts', $drafts ? 'info' : ''),
            ],
            'overdue' => $overdue > 0 ? Format::money($overdue) . ' ' . $currency . ' is overdue.' : null,
            'invoices' => array_map([Invoices::class, 'row'], $invoices),
            'payments' => array_map(static fn (array $p) => [
                'id' => (int) $p['id'],
                'date' => (string) $p['payment_date'],
                'invoiceId' => (int) $p['invoice_id'],
                'invoice' => (string) $p['invoice_number'],
                'amount' => Format::money($p['amount']) . ' ' . $p['currency'],
                'method' => BillingController::PAYMENT_METHODS[$p['method']] ?? (string) $p['method'],
                'reference' => (string) ($p['reference'] ?: '-'),
            ], Db::select('SELECT p.*, i.invoice_number FROM billing_payments p JOIN billing_invoices i ON i.id=p.invoice_id WHERE p.username=? ORDER BY p.payment_date DESC, p.id DESC LIMIT 50', [$client['username']])),
        ];
    }

    public function overview(string $username): JsonResponse
    {
        $row = Db::row('SELECT * FROM customer_profiles WHERE username = ?', [$username]);
        abort_if(!$row, 404, 'Client not found.');
        return response()->json(Clients::overview($row))->header('Cache-Control', 'no-store');
    }

    public function save(Request $request): RedirectResponse
    {
        Auth::requireCan('clients.manage');
        $input = $this->formInput($request);
        $tab = in_array($input['_active_tab'] ?? '', self::TABS, true) ? (string) $input['_active_tab'] : 'account';
        unset($input['_active_tab']);
        $oldUsername = (string) ($input['old_username'] ?? '');
        $before = $oldUsername !== '' ? Db::row('SELECT * FROM customer_profiles WHERE username=?', [$oldUsername]) : null;
        $formPath = $oldUsername !== '' ? '/clients/' . rawurlencode($oldUsername) . '/edit' : '/clients/create';
        $returnPath = $formPath . '?tab=' . $tab;

        // The form's three-way status and the static IP choice map onto stored fields.
        $status = (string) ($input['account_status'] ?? 'enabled');
        $input['framed_ip_address'] = trim((string) ($input['framed_ip_address'] ?? ''));
        $input['ip_mode'] = $input['framed_ip_address'] !== '' ? 'static' : 'pool';
        $input['enabled'] = $status === 'enabled' ? '1' : '0';
        $input['account_status'] = $status === 'enabled' ? 'active' : (in_array($status, ['disabled', 'suspended'], true) ? $status : 'active');

        try {
            $input = RadiusRepository::normalizeClientIdentity($input);
            // Setting a password or changing the RADIUS identity needs a fresh sign-in confirmation.
            if (!empty($input['password']) || ($before && ($before['username'] !== $input['username'] || ($before['auth_method'] ?? 'pppoe') !== $input['auth_method']))) {
                if (!Auth::recentlyAuthenticated()) {
                    FormDrafts::remember('client', $formPath, $this->formInput($request), $tab);
                    Auth::rememberReauthReturn($returnPath);
                    return redirect('/reauth');
                }
            }
            if ($before && !SchemaCompatibility::problems('policy')) {
                $suspension = Db::value('SELECT suspension_source FROM subscriptions WHERE customer_id=?', [$before['id']]);
                if ($suspension === 'security' && !Auth::can('security.manage')) {
                    throw new RuntimeException('Security suspension can only be changed by security administration.');
                }
            }
            $uploads = Documents::validateUploads();
            $savedUsername = RadiusRepository::saveClient($input, $oldUsername !== '' ? $oldUsername : null);
        } catch (RuntimeException $e) {
            return $this->failKeepingInput($e, $returnPath, self::FIELD_ERRORS);
        }
        Audit::log($before ? 'update' : 'create', 'client', $savedUsername, $before, array_diff_key($input, ['password' => true]) + ['saved_username' => $savedUsername, 'password_changed' => !empty($input['password'])]);
        $edit = '/clients/' . rawurlencode($savedUsername) . '/edit';
        try {
            Documents::store($savedUsername, $uploads, (int) Auth::id(), self::moveUpload(...));
        } catch (Throwable $e) {
            return $this->warn($edit, 'Policy saved; network application pending. Documents were not saved; upload them again. Reference: ' . Diagnostics::report($e));
        }
        return $this->ok($edit . '?tab=' . $tab, 'Policy saved. Network application is pending; see connection diagnostics.');
    }

    /** Uploads were validated by Security::uploadInfo; under PHPUnit they are local temp files. */
    private static function moveUpload(string $source, string $target): bool
    {
        if (is_uploaded_file($source)) {
            return move_uploaded_file($source, $target);
        }
        return app()->runningUnitTests() && rename($source, $target);
    }

    public function revealPassword(string $username): RedirectResponse
    {
        Auth::requireCan('clients.manage');
        $password = Auth::revealClientPassword($username);
        // Flashed for one page view: never put in a URL or the browser history.
        return redirect('/clients/' . rawurlencode($username) . '/edit')
            ->with('revealed_password', $password !== '' ? $password : '(no saved password)')
            ->with('flash', ['type' => 'info', 'message' => 'This reveal was recorded in the audit log.']);
    }

    public function bulk(Request $request): RedirectResponse
    {
        $action = (string) $request->input('bulk_action', '');
        if ($action === 'delete') {
            $action = 'archive';
        }
        $usernames = array_values(array_unique(array_filter(array_map('strval', (array) $request->input('clients', [])))));
        if (!$usernames) {
            return $this->fail('Select at least one client.');
        }
        match ($action) {
            'suspend', 'disable' => Auth::requireCan('clients.manage'),
            'archive' => Auth::requireCan('danger.delete'),
            // Bulk disconnect is not offered: a disconnect must target one exact session.
            default => null,
        };
        if (!in_array($action, ['suspend', 'disable', 'archive'], true)) {
            return $this->fail('Choose a valid bulk action.');
        }
        $sent = 0;
        $errors = [];
        foreach ($usernames as $username) {
            try {
                $action === 'archive' ? RadiusRepository::archiveClient($username) : RadiusRepository::setClientStatus($username, $action === 'suspend' ? 'suspended' : 'disabled');
                $sent++;
            } catch (Throwable $e) {
                $errors[] = $username . ': ' . substr($e->getMessage(), 0, 90);
            }
        }
        $failed = count($errors);
        Audit::log('bulk_' . $action, 'client', 'multiple', null, ['clients' => $usernames, 'success' => $sent, 'failed' => $failed]);
        return $this->ok('/clients', ucfirst($action) . " completed for {$sent} client(s). {$failed} failed." . ($failed ? ' Failed: ' . implode('; ', array_slice($errors, 0, 3)) : ''));
    }

    public function delete(string $username): RedirectResponse
    {
        Auth::requireCan('danger.delete');
        $before = Db::row('SELECT * FROM customer_profiles WHERE username=?', [$username]);
        try {
            RadiusRepository::archiveClient($username);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), '/clients');
        }
        Audit::log('archive', 'client', $username, $before);
        return $this->ok('/clients', 'Client archived; billing and document history retained. Existing sessions are not disconnected.');
    }

    public function disconnect(Request $request, string $username): RedirectResponse
    {
        Auth::requireCan('clients.disconnect');
        $back = '/clients/' . rawurlencode($username) . '/edit';
        try {
            $operationId = NetworkOperations::requestDisconnect($username, (int) $request->input('nas_id', 0), (int) $request->input('radacctid', 0));
            Audit::log('disconnect', 'client', $username, null, ['operation_id' => $operationId]);
            return $this->ok($back, 'Disconnect queued. Network completion has not been verified.');
        } catch (Throwable $e) {
            Audit::log('disconnect_failed', 'client', $username, null, ['error' => $e->getMessage()]);
            return $this->fail(substr($e->getMessage(), 0, 220), $back);
        }
    }

    public function diagnostics(string $username): Response
    {
        abort_if(!Db::value('SELECT 1 FROM customer_profiles WHERE username = ?', [$username]), 404, 'Client ' . $username . ' was not found. It may have been renamed.');
        $d = ConnectionDiagnostics::forCustomer($username);
        $c = $d['client'];
        $p = $d['profile'];
        $r = $d['session'];
        $policy = $d['policy'];
        $deadlinePassed = !empty($policy['valid_until']) && (int) $policy['valid_until'] <= time();
        $eligible = !empty($policy['allowed']) && !$deadlinePassed;
        $replies = implode('; ', array_map(static fn ($a) => $a[0] . ' ' . $a[1] . ' ' . $a[2], $d['effective']['replies'] ?? []));
        $age = $r && ($t = strtotime($r['acctupdatetime'] ?? $r['acctstarttime'] ?? '')) ? max(0, time() - $t) . ' seconds' : 'unknown';
        $rows = [
            ['Billing state', $c['payment_status']],
            ['Service entitlement', Clients::statusLabel($c)],
            ['Suspension source', $c['suspension_source'] ?? 'none'],
            ['Network provisioning', $c['provisioning_state'] ?? 'unpublished'],
            ['Desired / verified revision', ($c['policy_revision'] ?? 0) . ' / ' . ($c['applied_revision'] ?? 'none')],
            ['Observed connection', $r ? Accounting::state($r) : 'unknown'],
            ['Last authentication', $d['auth'] ? ($d['auth']['reply'] . ' at ' . substr((string) $d['auth']['authdate'], 0, 19)) : 'unknown'],
            ['Recorded access decision', $d['decision'] ? ($d['decision']['decision'] . ' at ' . $d['decision']['occurred_at'] . ' via ' . $d['decision']['adapter']) : 'unknown'],
            ['Last authentication reason', $d['decision']['reason'] ?? 'Not recorded by legacy radpostauth; current policy below is not a historical decision.'],
            ['Current authorization policy', $eligible ? 'eligible (PPP still requires credentials)' : 'blocked'],
            ['Current policy reason', $deadlinePassed ? 'authorization_deadline_elapsed' : ($policy['reason'] ?? 'not published')],
            ['NAS', $p['source_ip'] ?? 'unbound'],
            ['Access adapter', $p['adapter'] ?? 'unbound'],
            ['Model / release', ($p['model'] ?? 'unknown') . ' / ' . ($p['software_version'] ?? 'unknown')],
            ['Selected plan', $c['service_group']],
            ['Published effective plan', $d['effective']['effective_group'] ?? 'not published'],
            ['Generated reply policy', $replies ?: 'none'],
            ['Address', $r['framedipaddress'] ?? $c['framed_ip_address'] ?? 'unknown'],
            ['Accounting age', $age],
            ['Expected interval / grace', $p ? ($p['expected_interval'] . ' / ' . $p['grace_seconds'] . ' seconds') : 'unknown; conservative legacy freshness'],
            ['MAC', $c['mac_address'] ?? 'none'],
            ['DHCP client ID', $c['dhcp_client_id'] ?? 'not bound'],
            ['Trusted circuit', $c['circuit_id'] ?? 'not bound'],
            ['Capability gate', AccessPolicy::capability($p, 'apply') ?? 'Recorded acceptance; observe each operation'],
        ];
        return Inertia::render('clients/Diagnostics', [
            'username' => $username,
            'signals' => array_map(static fn ($row) => ['signal' => $row[0], 'evidence' => (string) ($row[1] ?? '')], $rows),
            'operations' => array_map(static fn ($o) => [
                'id' => (int) $o['id'], 'action' => (string) $o['action'], 'revision' => (string) $o['policy_revision'],
                'state' => (string) $o['state'], 'attempts' => (int) $o['attempts'], 'result' => (string) ($o['result_code'] ?? 'pending'),
            ], $d['operations']),
            'sources' => Auth::can('security.manage') ? ['manual' => 'Manual', 'security' => 'Security'] : ['manual' => 'Manual'],
        ]);
    }

    public function suspension(Request $request, string $username): RedirectResponse
    {
        Auth::requireCan('clients.suspend');
        SchemaCompatibility::require('policy');
        $source = (string) $request->input('source', 'manual');
        $restore = $request->input('action') === 'restore';
        if (!in_array($source, ['manual', 'security'], true)) {
            return $this->fail('Invalid suspension source.');
        }
        if ($source === 'security') {
            Auth::requireCan('security.manage');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $c = Db::row('SELECT * FROM customer_profiles WHERE username=? FOR UPDATE', [$username]);
            if (!$c || $c['account_status'] === 'archived') {
                throw new RuntimeException('Customer unavailable.');
            }
            $s = SubscriberPolicy::ensure((int) $c['id']);
            if ($s['suspension_source'] === 'security') {
                Auth::requireCan('security.manage');
            }
            Db::exec('UPDATE subscriptions SET suspension_source=? WHERE id=?', [$restore ? null : $source, $s['id']]);
            Db::exec('UPDATE customer_profiles SET enabled=?,account_status=? WHERE id=?', [$restore ? 1 : 0, $restore ? 'active' : 'suspended', $c['id']]);
            RadiusRepository::provisionClient($username);
            Audit::log($restore ? 'restore_entitlement' : 'suspend_entitlement', 'client', $username, null, ['source' => $source]);
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            SubscriberPolicy::releaseLocks();
            throw $e;
        }
        return $this->ok('/clients/' . rawurlencode($username) . '/diagnostics', 'Entitlement saved. Network enforcement remains pending verification.');
    }

    public function documentDelete(): RedirectResponse
    {
        Auth::requireCan('documents.manage');
        return $this->fail('Documents are retained indefinitely. Removal requires a separately approved retention policy.');
    }

    public function documentDownload(string $id): BinaryFileResponse
    {
        Auth::requireCan('documents.view');
        try {
            [$doc, $path] = Documents::download((int) $id);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
        return response()->download($path, (string) $doc['original_name'], [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
