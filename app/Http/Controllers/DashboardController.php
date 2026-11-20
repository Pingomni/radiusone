<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Format;
use App\Http\Support\MonitoringData;
use App\Http\Support\Present;
use App\Support\Accounting;
use App\Support\Auth;
use App\Support\ClientQuery;
use App\Support\Currency;
use App\Support\Db;
use App\Support\Entitlement;
use App\Support\License;
use App\Support\MicroCache;
use App\Support\NasMonitor;
use App\Support\NasRepository;
use App\Support\Olt\OltInventory;
use App\Support\Olt\OltManager;
use App\Support\Olt\Olts;
use App\Support\SchemaCompatibility;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The Overview page (the dashboard) and the JSON data its live panels poll. */
final class DashboardController extends Controller
{
    public function dashboard(): Response
    {
        // Sessions started this month (indexed range); all-time totals meant summing the whole history on every view.
        $topUsers = MicroCache::remember('dashboard:top-users:' . date('Y-m'), 300, static fn () => Db::select(
            'SELECT username, COALESCE(SUM(acctoutputoctets),0) AS down, COALESCE(SUM(acctinputoctets),0) AS up FROM radacct
             WHERE acctstarttime >= DATE_FORMAT(CURDATE(), "%Y-%m-01") GROUP BY username
             ORDER BY COALESCE(SUM(acctinputoctets),0) + COALESCE(SUM(acctoutputoctets),0) DESC LIMIT 6'
        ));
        $showRejects = Auth::can('logs.view') && Db::value("SHOW TABLES LIKE 'radpostauth'");
        $routers = self::routers();
        $attention = self::attention($routers);
        $online = ClientQuery::count(['status' => 'online']);
        $fibre = self::fibre();

        return Inertia::render('Dashboard', [
            'state' => [
                'headline' => self::headline($attention),
                'line' => number_format($online) . ' ' . ($online === 1 ? 'client is' : 'clients are') . ' online.',
            ],
            'kpis' => self::kpis($online, $fibre),
            'fibre' => $fibre === null ? null : ['olts' => $fibre['olts'], 'chart' => $fibre['chart']],
            'routers' => $routers,
            'routerTotal' => (int) Db::value('SELECT COUNT(*) FROM nas'),
            'attention' => $attention,
            'expiring' => Auth::can('clients.view') ? self::expiring() : null,
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
            'dashboard-fibre' => ['olts' => (self::fibre() ?? abort(404))['olts']],
            'dashboard-nas' => self::routers(),
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

    /**
     * Key figures for the strip. Expired needs follow-up (amber); figures that match a client filter link to the list.
     *
     * @param  array<string, mixed>|null  $fibre
     */
    private static function kpis(?int $online = null, ?array $fibre = null): array
    {
        $counts = self::clientCounts();
        $today = (int) Db::value('SELECT COALESCE(SUM(COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= CURDATE() AND acctstarttime < DATE_ADD(CURDATE(), INTERVAL 1 DAY)');
        $clients = Auth::can('clients.view');
        $reports = Auth::can('reports.view') ? '/reports' : '';
        $metric = static fn (string $label, string|int $value, string $icon, string $tone = '', string $href = '', string $note = '') => ['label' => $label, 'value' => is_int($value) ? number_format($value) : $value, 'icon' => $icon, 'tone' => $tone, 'href' => $href, 'note' => $note];
        $expired = $counts['expired'] ?? 0;
        $onlineNow = $online ?? ClientQuery::count(['status' => 'online']);
        $fibre ??= self::fibre();
        if ($fibre !== null) {
            // With an OLT: clients and ONUs in one strip of six. Active, disabled and today's data ride as the small lines.
            return [
                $metric('Clients online', $onlineNow, 'clients', '', $clients ? '/clients?status=online' : '', number_format($counts['enabled'] ?? 0) . ' active · ' . Format::bytes($today) . ' today'),
                $metric('Expired', $expired, 'calendar', $expired > 0 ? 'warning' : '', $clients ? '/clients?status=expired' : '', number_format($counts['disabled'] ?? 0) . ' disabled'),
                ...$fibre['figures'],
            ];
        }
        $month = (int) Db::value('SELECT COALESCE(SUM(COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= DATE_FORMAT(CURDATE(), "%Y-%m-01") AND acctstarttime < DATE_ADD(DATE_FORMAT(CURDATE(), "%Y-%m-01"),INTERVAL 1 MONTH)');

        return [
            $metric('Online now', $onlineNow, 'clients', '', $clients ? '/clients?status=online' : ''),
            $metric('Active clients', $counts['enabled'] ?? 0, 'user', '', $clients ? '/clients?status=enabled' : '', number_format($counts['total'] ?? 0) . ' in total'),
            $metric('Expired', $expired, 'calendar', $expired > 0 ? 'warning' : '', $clients ? '/clients?status=expired' : ''),
            $metric('Disabled', $counts['disabled'] ?? 0, 'lock', '', $clients ? '/clients?status=disabled' : ''),
            $metric('Data today', Format::bytes($today), 'activity', '', $reports),
            $metric('Data this month', Format::bytes($month), 'reports', '', $reports),
        ];
    }

    /**
     * The fibre side, as SmartOLT's four boxes: ONUs waiting to be added, online, offline (by cause) and with a weak
     * signal, each linking to its list; one row per OLT; and the ONU graph of the last day. Null without an OLT.
     *
     * @return array{figures: list<array<string, mixed>>, olts: list<array<string, mixed>>, chart: ?array<string, mixed>}|null
     */
    private static function fibre(): ?array
    {
        if (!Auth::can('nas.view') || SchemaCompatibility::problems('olt')) {
            return null;
        }
        $olts = Db::select('SELECT t.*, COUNT(o.id) AS onus,
            COALESCE(SUM(o.status = "online"), 0) AS online,
            COALESCE(SUM(o.status <> "online"), 0) AS offline,
            COALESCE(SUM(o.status <> "online" AND o.down_cause LIKE "%dying%"), 0) AS power,
            COALESCE(SUM(o.status <> "online" AND o.down_cause LIKE "%LOS%"), 0) AS los,
            COALESCE(SUM(' . Olts::weakSql('o', 't.rx_warning') . '), 0) AS weak,
            COALESCE(SUM(' . Olts::weakSql('o', 't.rx_critical') . '), 0) AS very_weak
            FROM olts t LEFT JOIN olt_onus o ON o.olt_id = t.id WHERE t.enabled = 1 GROUP BY t.id ORDER BY t.name');
        if (!$olts) {
            return null;
        }
        $one = count($olts) === 1 ? '/olts/' . (int) $olts[0]['id'] : null;
        $sum = static fn (string $key) => array_sum(array_map(static fn (array $o) => (int) $o[$key], $olts));
        $waiting = array_map(static fn (array $o) => count(OltInventory::saved($o)['waiting'] ?? []), $olts);
        $rows = [];
        foreach ($olts as $i => $o) {
            $read = $o['last_sync_ok'] === null ? ['neutral', 'Not read yet'] : ((int) $o['last_sync_ok'] === 1 ? ['good', 'Reading'] : ['danger', 'Cannot be read']);
            $rows[] = ['id' => (int) $o['id'], 'name' => (string) $o['name'], 'host' => (string) $o['host'], 'tone' => $read[0], 'status' => $read[1], 'error' => (string) ($o['last_error'] ?? ''),
                'online' => (int) $o['online'], 'onus' => (int) $o['onus'], 'offline' => (int) $o['offline'], 'weak' => (int) $o['weak'], 'waiting' => $waiting[$i],
                'read' => $o['last_sync_at'] === null ? null : substr((string) $o['last_sync_at'], 11, 5), 'canAdd' => Auth::can('nas.manage') && OltManager::canChange($o)];
        }
        $offline = $sum('offline');
        $other = $offline - $sum('power') - $sum('los');
        $weak = $sum('weak');
        $metric = static fn (string $label, int $value, string $icon, string $tone, string $href, string $note) => ['label' => $label, 'value' => number_format($value), 'icon' => $icon, 'tone' => $tone, 'href' => $href, 'note' => $note];
        $list = static fn (string $status) => $one === null ? '/olts' : $one . '?status=' . $status;
        $waitingTotal = array_sum($waiting);
        return [
            'figures' => [
                $metric('ONUs waiting', $waitingTotal, 'plus', $waitingTotal > 0 ? 'warning' : '', $one !== null && $rows[0]['canAdd'] ? $one . '/provision' : ($one ?? '/olts'), $waitingTotal > 0 ? 'Not added yet' : 'None to add'),
                $metric('ONUs online', $sum('online'), 'fiber', '', $list('online'), 'of ' . number_format($sum('onus'))),
                $metric('ONUs offline', $offline, 'offline', $offline > 0 ? 'warning' : '', $list('offline'), 'Power ' . $sum('power') . ' · LOS ' . $sum('los') . ' · Other ' . $other),
                $metric('Weak signal', $weak, 'alert', $sum('very_weak') > 0 ? 'danger' : ($weak > 0 ? 'warning' : ''), $list('weak'), 'Warning ' . ($weak - $sum('very_weak')) . ' · Critical ' . $sum('very_weak')),
            ],
            'olts' => $rows,
            'chart' => $one === null ? null : ['url' => $one . '/graph?chart=onus&range=24h', 'data' => MonitoringData::oltChart($olts[0], '24h')],
        ];
    }

    public static function nasRows(): array
    {
        return Db::select('SELECT n.id, n.shortname, n.nasname, n.type, m.config_revision, m.applied_revision, m.pending_delete, COALESCE(m.vendor, n.type) AS vendor, COALESCE(m.enabled, 1) AS enabled, COALESCE(m.coa_enabled, 1) AS coa_enabled, m.coa_port, (COALESCE(n.secret, "") <> "") AS secret_saved, m.api_host, m.api_user, (COALESCE(m.api_password, "") <> "") AS api_password_saved, ' . NasRepository::apiEnabledSql('m') . ' AS api_enabled, COUNT(r.radacctid) AS online_users FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id LEFT JOIN radacct r ON ' . Accounting::freshSql('r') . ' AND (r.nasipaddress=n.nasname OR r.nasipaddress=n.shortname) GROUP BY n.id, m.enabled, m.coa_enabled, m.coa_port, m.vendor, m.api_host, m.api_user, m.api_password ORDER BY n.shortname LIMIT 6');
    }

    /**
     * The routers table: one parallel SNMP GET for the listed NAS (at most 6, bounded at 1.5 s), the latest CPU sample
     * from the last 15 minutes, and one status word per router.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function routers(): array
    {
        $rows = self::nasRows();
        $snmp = NasMonitor::probeMany($rows);
        $cpu = [];
        try {
            foreach (Db::select('SELECT s.nas_id, s.cpu_pct FROM nas_snmp_health_samples s JOIN (SELECT nas_id, MAX(sampled_at) AS at FROM nas_snmp_health_samples WHERE sampled_at >= UNIX_TIMESTAMP() - 900 GROUP BY nas_id) l ON l.nas_id = s.nas_id AND l.at = s.sampled_at') as $r) {
                $cpu[(int) $r['nas_id']] = $r['cpu_pct'] === null ? null : (int) round((float) $r['cpu_pct']);
            }
        } catch (\Throwable) {
            $cpu = [];
        }
        $canViewNas = Auth::can('nas.view');

        return array_map(static function (array $r) use ($snmp, $cpu, $canViewNas) {
            $id = (int) $r['id'];
            $probe = $snmp[$id] ?? null;
            $sessions = (int) $r['online_users'];
            $waiting = ($r['applied_revision'] ?? null) === null || (int) $r['applied_revision'] !== (int) ($r['config_revision'] ?? 0);
            [$tone, $text, $title] = match (true) {
                (int) $r['enabled'] !== 1 => ['neutral', 'RADIUS off', 'RADIUS is turned off for this NAS'],
                !empty($r['pending_delete']) => ['warning', 'Removal pending', 'Removed in the portal; FreeRADIUS still has it until it restarts'],
                $probe !== null && $probe['state'] === 'down' => ['danger', 'Not answering', (string) ($probe['error'] ?? 'No SNMP response')],
                $waiting => ['warning', 'Waiting for restart', 'FreeRADIUS has not loaded the latest change for this NAS yet'],
                $probe !== null && $probe['state'] === 'up' => ['good', 'Online', 'Answering over SNMP in ' . $probe['latency_ms'] . ' ms'],
                $sessions > 0 => ['good', 'Online', 'Sending accounting'],
                default => ['neutral', 'No sessions', 'No SNMP set up and no live sessions'],
            };
            return [
                'id' => $id,
                'name' => (string) ($r['shortname'] ?: $r['nasname']),
                'vendor' => (string) ($r['vendor'] ?? ''),
                'address' => (string) $r['nasname'],
                'href' => $canViewNas && $id ? '/nas/' . $id . '/edit' : null,
                'tone' => $tone,
                'status' => $text,
                'title' => $title,
                'sessions' => $sessions,
                'cpu' => $cpu[$id] ?? null,
                'uptime' => $probe !== null && $probe['state'] === 'up' && $probe['uptime'] !== null ? Present::uptime((int) $probe['uptime']) : null,
            ];
        }, $rows);
    }

    /**
     * What needs a look, most urgent first: routers not answering, OLTs that cannot be read, ONUs with a weak signal,
     * routers waiting for a FreeRADIUS restart and overdue invoices. Each item names the page that fixes it.
     *
     * @param  array<int, array<string, mixed>>  $routers
     * @return list<array{tone:string, title:string, detail:string, action:?array{label:string, href:string}, phrase:string}>
     */
    private static function attention(array $routers): array
    {
        $items = [];
        $canNas = Auth::can('nas.view');
        $down = array_values(array_filter($routers, static fn (array $r) => $r['tone'] === 'danger'));
        foreach ($down as $r) {
            $items[] = ['tone' => 'danger', 'title' => $r['name'] . ' is not answering', 'detail' => $r['address'],
                'action' => $canNas ? ['label' => 'Diagnose', 'href' => '/nas/' . $r['id'] . '/diagnostics'] : null,
                'phrase' => count($down) === 1 ? 'One router is not answering' : count($down) . ' routers are not answering'];
        }
        if ($canNas) {
            try {
                $olts = Db::select('SELECT t.id, t.name, t.last_sync_ok, t.last_error, t.rx_warning, t.rx_critical, t.inventory,
                    COALESCE(SUM(' . Olts::weakSql('o', 't.rx_warning') . '), 0) AS weak,
                    COALESCE(SUM(' . Olts::weakSql('o', 't.rx_critical') . '), 0) AS very_weak
                    FROM olts t LEFT JOIN olt_onus o ON o.olt_id = t.id WHERE t.enabled = 1 GROUP BY t.id ORDER BY t.name');
            } catch (\Throwable) {
                $olts = [];
            }
            foreach ($olts as $o) {
                if ($o['last_sync_ok'] !== null && (int) $o['last_sync_ok'] === 0) {
                    $items[] = ['tone' => 'danger', 'title' => $o['name'] . ' cannot be read', 'detail' => mb_strimwidth((string) $o['last_error'], 0, 90, '…'),
                        'action' => ['label' => 'Open', 'href' => '/olts/' . (int) $o['id']], 'phrase' => $o['name'] . ' cannot be read'];
                }
                // A rogue ONU takes a whole PON port down: as urgent as an OLT that cannot be read.
                $rogue = array_keys(OltInventory::rogueValues(OltInventory::saved($o)));
                if ($rogue) {
                    $items[] = ['tone' => 'danger', 'title' => (count($rogue) === 1 ? 'Rogue ONU on port ' . $rogue[0] : 'Rogue ONUs on ' . count($rogue) . ' ports') . ' of ' . $o['name'],
                        'detail' => count($rogue) === 1 ? 'Every ONU on this port may be offline.' : implode(', ', $rogue),
                        'action' => ['label' => 'See ports', 'href' => '/olts/' . (int) $o['id'] . '?tab=ports'], 'phrase' => count($rogue) === 1 ? 'a rogue ONU is on ' . $o['name'] : 'rogue ONUs are on ' . $o['name']];
                }
            }
            $weakTotal = array_sum(array_map(static fn (array $o) => (int) $o['weak'], $olts));
            foreach ($olts as $o) {
                $weak = (int) $o['weak'];
                if ($weak === 0) {
                    continue;
                }
                $port = Db::row('SELECT o.frame, o.slot, o.port, COUNT(*) AS n FROM olt_onus o WHERE o.olt_id = ? AND ' . Olts::weakSql('o', '?') . ' GROUP BY o.frame, o.slot, o.port ORDER BY n DESC LIMIT 1', [(int) $o['id'], $o['rx_warning'], $o['rx_warning']]);
                $items[] = [
                    'tone' => (int) $o['very_weak'] > 0 ? 'danger' : 'warning',
                    'title' => ($weak === 1 ? 'One ONU has' : $weak . ' ONUs have') . ' a weak signal on ' . $o['name'],
                    'detail' => $port && (int) $port['n'] > 1 && $weak > 1 ? $port['n'] . ' of them on port ' . $port['frame'] . '/' . $port['slot'] . '/' . $port['port'] . '.' : 'Below ' . rtrim(rtrim((string) $o['rx_warning'], '0'), '.') . ' dBm.',
                    'action' => ['label' => 'See them', 'href' => '/olts/' . (int) $o['id'] . '?status=weak'],
                    'phrase' => $weakTotal === 1 ? 'one ONU has a weak signal' : $weakTotal . ' ONUs have a weak signal',
                ];
            }
        }
        $waiting = array_filter($routers, static fn (array $r) => $r['status'] === 'Waiting for restart');
        if ($waiting) {
            $items[] = ['tone' => 'warning', 'title' => (count($waiting) === 1 ? reset($waiting)['name'] . ' waits' : count($waiting) . ' routers wait') . ' for a FreeRADIUS restart',
                'detail' => 'Changes to them are not in use yet.', 'action' => $canNas ? ['label' => 'Open', 'href' => '/nas'] : null, 'phrase' => ''];
        }
        if (Auth::can('billing.view') && License::allows('billing')) {
            $rows = Db::select('SELECT currency, COUNT(*) AS n, COALESCE(SUM(balance_due), 0) AS due FROM billing_invoices WHERE status = "overdue" GROUP BY currency');
            $count = array_sum(array_map(static fn (array $r) => (int) $r['n'], $rows));
            if ($count > 0) {
                $items[] = ['tone' => 'warning', 'title' => ($count === 1 ? 'One invoice is' : number_format($count) . ' invoices are') . ' overdue',
                    'detail' => Currency::totals(array_column($rows, 'due', 'currency')) . ' to collect.',
                    'action' => ['label' => 'Review', 'href' => '/billing?status=overdue'], 'phrase' => ($count === 1 ? 'one invoice is' : number_format($count) . ' invoices are') . ' overdue'];
            }
        }
        usort($items, static fn (array $a, array $b) => ($a['tone'] === 'danger' ? 0 : 1) <=> ($b['tone'] === 'danger' ? 0 : 1));
        return $items;
    }

    /**
     * The page's title: the state of the network in one sentence, from the two most urgent kinds of problem.
     *
     * @param  array<int, array<string, mixed>>  $attention
     */
    private static function headline(array $attention): string
    {
        $phrases = array_values(array_unique(array_filter(array_column($attention, 'phrase'))));
        if (!$phrases) {
            return $attention ? 'Almost everything is working.' : 'Everything is working.';
        }
        $text = ucfirst($phrases[0]) . (isset($phrases[1]) ? ' and ' . lcfirst($phrases[1]) : '');
        return $text . '.';
    }

    /**
     * Active clients whose service ends in the next 7 days, soonest first; how many in all.
     *
     * @return array<string, mixed>
     */
    private static function expiring(): array
    {
        $today = Entitlement::today();
        $until = date('Y-m-d', (int) strtotime($today . ' +7 days'));
        $where = 'c.account_status = "active" AND c.enabled = 1 AND c.expiry_date >= ? AND c.expiry_date <= ?';
        $rows = Db::select("SELECT c.username, c.first_name, c.last_name, c.company_name, c.expiry_date, c.balance, s.name AS service
            FROM customer_profiles c LEFT JOIN service_metadata s ON s.group_name = c.service_group WHERE $where ORDER BY c.expiry_date, c.username LIMIT 6", [$today, $until]);
        $showBalance = Auth::can('billing.view');

        return [
            'total' => (int) Db::value("SELECT COUNT(*) FROM customer_profiles c WHERE $where", [$today, $until]),
            'rows' => array_map(static function (array $r) use ($today, $showBalance) {
                $days = (int) round((strtotime((string) $r['expiry_date']) - strtotime($today)) / 86400);
                return [
                    'username' => (string) $r['username'],
                    'name' => trim($r['first_name'] . ' ' . $r['last_name']) ?: (string) $r['company_name'],
                    'service' => (string) ($r['service'] ?? ''),
                    'expires' => (string) $r['expiry_date'],
                    'when' => match (true) {
                        $days <= 0 => 'Today', $days === 1 => 'Tomorrow', default => 'In ' . $days . ' days'
                    },
                    'soon' => $days <= 2,
                    'balance' => $showBalance ? (float) $r['balance'] : null,
                    'balanceText' => $showBalance ? Currency::amount($r['balance']) : null,
                ];
            }, $rows),
        ];
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
