<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Format;
use App\Http\Support\Present;
use App\Support\Accounting;
use App\Support\Auth;
use App\Support\ClientQuery;
use App\Support\Db;
use App\Support\Entitlement;
use App\Support\MicroCache;
use App\Support\NasMonitor;
use App\Support\NasRepository;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Dashboard page and the JSON data its live panels poll. */
final class DashboardController extends Controller
{
    public function dashboard(): Response
    {
        // Sessions started this month (indexed range); all-time totals meant summing the whole history on every view.
        $topUsers = MicroCache::remember('dashboard:top-users:' . date('Y-m'), 300, static fn () => Db::select(
            'SELECT username, COALESCE(SUM(acctoutputoctets),0) AS down, COALESCE(SUM(acctinputoctets),0) AS up FROM radacct
             WHERE acctstarttime >= DATE_FORMAT(CURDATE(), "%Y-%m-01") GROUP BY username
             ORDER BY COALESCE(SUM(acctinputoctets),0) + COALESCE(SUM(acctoutputoctets),0) DESC LIMIT 8'
        ));
        $showRejects = Auth::can('logs.view') && Db::value("SHOW TABLES LIKE 'radpostauth'");

        return Inertia::render('Dashboard', [
            'kpis' => self::kpis(),
            'nas' => self::nasCards(),
            'rejects' => $showRejects ? self::rejects() : null,
            'topUsers' => array_map(static fn (array $r) => [
                'username' => (string) $r['username'],
                'down' => Format::bytes((int) $r['down']),
                'up' => Format::bytes((int) $r['up']),
                'total' => Format::bytes((int) $r['down'] + (int) $r['up']),
            ], $topUsers),
        ]);
    }

    /** Read-only JSON polled by the live panels (same URLs as the legacy HTML fragments). */
    public function fragment(string $fragment): JsonResponse
    {
        Auth::requireCan($fragment === 'dashboard-rejects' ? 'logs.view' : 'dashboard.view');
        return response()->json(match ($fragment) {
            'dashboard-kpis' => self::kpis(),
            'dashboard-nas' => self::nasCards(),
            'dashboard-rejects' => self::rejects(),
            default => abort(404),
        })->header('Cache-Control', 'no-store');
    }

    public static function clientCounts(?int $now = null): array
    {
        // One business date for every status card, even if the request crosses midnight.
        $counts = Db::row('SELECT COUNT(*) AS total,
            COALESCE(SUM(enabled = 1 AND account_status = "active" AND NOT expired),0) AS enabled,
            COALESCE(SUM(expired),0) AS expired,
            COALESCE(SUM((enabled = 0 OR account_status IN ("disabled", "suspended")) AND NOT expired),0) AS disabled
            FROM (SELECT enabled, account_status,
                (account_status = "expired" OR (expiry_date IS NOT NULL AND expiry_date < ?)) AS expired
                FROM customer_profiles) statuses', [Entitlement::today($now)]);
        return array_map(static fn ($count): int => (int) $count, $counts ?? []);
    }

    /** Key figures. Expired needs follow-up (amber); only cards matching a client filter exactly link to a list. */
    private static function kpis(): array
    {
        $counts = self::clientCounts();
        $today = (int) Db::value('SELECT COALESCE(SUM(COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= CURDATE() AND acctstarttime < DATE_ADD(CURDATE(), INTERVAL 1 DAY)');
        $month = (int) Db::value('SELECT COALESCE(SUM(COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= DATE_FORMAT(CURDATE(), "%Y-%m-01") AND acctstarttime < DATE_ADD(DATE_FORMAT(CURDATE(), "%Y-%m-01"),INTERVAL 1 MONTH)');
        $clients = Auth::can('clients.view');
        $metric = static fn (string $label, string|int $value, string $icon, string $tone = '', string $href = '') => compact('label', 'icon', 'tone', 'href') + ['value' => (string) $value];

        return [
            $metric('Online clients', ClientQuery::count(['status' => 'online']), 'signal', '', $clients ? '/clients?status=online' : ''),
            $metric('Enabled clients', $counts['enabled'] ?? 0, 'user'),
            $metric('Expired clients', $counts['expired'] ?? 0, 'shield', ($counts['expired'] ?? 0) > 0 ? 'warning' : ''),
            $metric('Disabled clients', $counts['disabled'] ?? 0, 'lock'),
            $metric('NAS devices', (int) Db::value('SELECT COUNT(*) FROM nas'), 'server', '', Auth::can('nas.view') ? '/nas' : ''),
            $metric('Session totals: started today', Format::bytes($today), 'signal'),
            $metric('Session totals: started this month', Format::bytes($month), 'signal'),
            $metric('Total clients', $counts['total'] ?? 0, 'user', '', $clients ? '/clients' : ''),
        ];
    }

    public static function nasRows(): array
    {
        return Db::select('SELECT n.id, n.shortname, n.nasname, n.type, m.config_revision, m.applied_revision, m.pending_delete, COALESCE(m.vendor, n.type) AS vendor, COALESCE(m.enabled, 1) AS enabled, COALESCE(m.coa_enabled, 1) AS coa_enabled, m.coa_port, (COALESCE(n.secret, "") <> "") AS secret_saved, m.api_host, m.api_user, (COALESCE(m.api_password, "") <> "") AS api_password_saved, ' . NasRepository::apiEnabledSql('m') . ' AS api_enabled, COUNT(r.radacctid) AS online_users FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id LEFT JOIN radacct r ON ' . Accounting::freshSql('r') . ' AND (r.nasipaddress=n.nasname OR r.nasipaddress=n.shortname) GROUP BY n.id, m.enabled, m.coa_enabled, m.coa_port, m.vendor, m.api_host, m.api_user, m.api_password ORDER BY n.shortname LIMIT 6');
    }

    /** NAS status cards; one parallel SNMP GET for the listed devices (at most 6), bounded at 1.5 s. */
    private static function nasCards(): array
    {
        $rows = self::nasRows();
        $snmp = NasMonitor::probeMany($rows);
        $canViewNas = Auth::can('nas.view');
        return array_map(static function (array $r) use ($snmp, $canViewNas) {
            $id = (int) $r['id'];
            $tags = [];
            if ((int) $r['enabled'] !== 1) {
                $tags[] = Present::tag('RADIUS disabled', 'warning');
            }
            $tags[] = Present::nasDisconnect($r);
            $tags[] = Present::tag($r['online_users'] . ' fresh ' . ((int) $r['online_users'] === 1 ? 'session' : 'sessions'));
            $status = $snmp[$id] ?? null;
            if ($status && $status['state'] !== 'off') {
                $up = $status['state'] === 'up';
                $text = $up ? 'SNMP ' . $status['latency_ms'] . ' ms' : 'SNMP no response';
                $title = $up ? 'Responding over SNMP' . ($status['uptime'] !== null ? '; uptime ' . Present::uptime((int) $status['uptime']) : '') : (string) ($status['error'] ?? 'No SNMP response');
                $tags[] = Present::tag($text, $up ? '' : 'danger', $title, $canViewNas ? '/nas/' . $id . '/monitor' : null);
            }
            return [
                'id' => $id,
                'name' => (string) ($r['shortname'] ?: $r['nasname']),
                'address' => (string) $r['nasname'],
                'href' => $canViewNas && $id ? '/nas/' . $id . '/edit' : null,
                'tags' => $tags,
            ];
        }, $rows);
    }

    private static function rejects(): array
    {
        return array_map(static fn (array $r) => [
            'username' => (string) $r['username'],
            'reply' => (string) $r['reply'],
            'authdate' => (string) $r['authdate'],
        ], Db::select('SELECT username, reply, authdate FROM radpostauth WHERE reply LIKE "%Reject%" ORDER BY authdate DESC LIMIT 8'));
    }
}
