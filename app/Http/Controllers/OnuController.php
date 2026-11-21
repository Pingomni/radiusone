<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Clients;
use App\Http\Support\MonitoringData;
use App\Support\Auth;
use App\Support\Db;
use App\Support\Olt\HuaweiCli;
use App\Support\Olt\OltChangelog;
use App\Support\Olt\OltInventory;
use App\Support\Olt\OltManager;
use App\Support\Olt\OltMapper;
use App\Support\Olt\OltMonitor;
use App\Support\Olt\Olts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** One ONU: its live details, and the changes the portal can make on the OLT (restart, turn off and on, rename, remove, add). */
final class OnuController extends Controller
{
    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the OLT and the ONU */
    private static function find(string $id, string $onu): array
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $row = Db::row('SELECT * FROM olt_onus WHERE id=? AND olt_id=?', [(int) $onu, $olt['id']]);
        abort_if(!$row, 404, 'ONU not found. It may have been removed from the OLT.');
        return [$olt, $row];
    }

    private const PAGE_SIZE = 50;

    /**
     * Every ONU on every OLT in one list: the place to find an ONU without first choosing its OLT. Filtered by state
     * (online, offline, weak, or "waiting": new on the fibre and not added yet), by OLT, and by name, serial or client.
     */
    public function index(Request $request): Response
    {
        $olts = Db::select('SELECT * FROM olts ORDER BY name');
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $status = in_array($status, ['online', 'offline', 'weak', 'waiting'], true) ? $status : '';
        $oltId = (int) $request->query('olt', 0);
        $oltId = in_array($oltId, array_map(static fn (array $o) => (int) $o['id'], $olts), true) ? $oltId : 0;

        // Every filter but the state narrows the counts too, so the tabs always add up to what the list shows.
        $scope = '';
        $scopeParams = [];
        if ($oltId) {
            $scope .= ' AND o.olt_id=?';
            $scopeParams[] = $oltId;
        }
        // A card ("0/1") and a PON port ("0/1/15").
        $board = trim((string) $request->query('board', ''));
        if (preg_match('~^(\d{1,2})/(\d{1,2})$~D', $board, $at)) {
            $scope .= ' AND o.frame=? AND o.slot=?';
            array_push($scopeParams, (int) $at[1], (int) $at[2]);
        } else {
            $board = '';
        }
        $port = trim((string) $request->query('port', ''));
        if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{1,3})$~D', $port, $at) && ($board === '' || str_starts_with($port, $board . '/'))) {
            $scope .= ' AND o.frame=? AND o.slot=? AND o.port=?';
            array_push($scopeParams, (int) $at[1], (int) $at[2], (int) $at[3]);
        } else {
            $port = '';
        }
        $onMac = 'm.olt_id=o.olt_id AND m.frame=o.frame AND m.slot=o.slot AND m.port=o.port AND m.ont_id=o.ont_id';
        // The VLANs the ONU's devices were last seen on, from the OLT's MAC table.
        $vlan = (int) $request->query('vlan', 0);
        if ($vlan > 0) {
            $scope .= ' AND EXISTS (SELECT 1 FROM olt_macs m WHERE ' . $onMac . ' AND m.vlan=?)';
            $scopeParams[] = $vlan;
        }
        $type = trim((string) $request->query('type', ''));
        if ($type !== '') {
            $scope .= ' AND o.onu_model=?';
            $scopeParams[] = $type;
        }
        $profile = trim((string) $request->query('profile', ''));
        if ($profile !== '') {
            $scope .= ' AND o.service_profile=?';
            $scopeParams[] = $profile;
        }
        $linked = (string) $request->query('linked', '');
        $linked = in_array($linked, ['yes', 'no'], true) ? $linked : '';
        if ($linked !== '') {
            $scope .= ' AND ' . ($linked === 'no' ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM customer_onus l WHERE l.olt_id=o.olt_id AND l.sn=o.sn AND l.source<>"none")';
        }
        if ($q !== '') {
            // Serial, name on the OLT, a MAC behind the ONU, or its client: username, name, address, phone or session IP.
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $mac = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $q));
            $client = 'SELECT 1 FROM customer_onus l JOIN customer_profiles c ON c.id=l.customer_id WHERE l.olt_id=o.olt_id AND l.sn=o.sn AND ';
            $scope .= ' AND (o.sn LIKE ? OR o.description LIKE ?'
                . ' OR EXISTS (' . $client . '(c.username LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ? OR c.company_name LIKE ? OR c.address LIKE ? OR c.phone LIKE ? OR c.cell_phone LIKE ? OR c.framed_ip_address LIKE ?'
                . ' OR EXISTS (SELECT 1 FROM radacct r WHERE r.username=c.username AND r.acctstoptime IS NULL AND r.framedipaddress LIKE ?)))'
                . (strlen($mac) >= 4 && strlen($mac) === strlen((string) preg_replace('/[\s:.\-]/', '', $q)) ? ' OR EXISTS (SELECT 1 FROM olt_macs m WHERE ' . $onMac . ' AND m.mac LIKE ?)' : '') . ')';
            array_push($scopeParams, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
            if (str_contains($scope, 'm.mac LIKE ?')) {
                $scopeParams[] = '%' . $mac . '%';
            }
        }
        $counts = Db::row('SELECT COUNT(*) AS total, COALESCE(SUM(o.status="online"),0) AS online, COALESCE(SUM(o.status="offline"),0) AS offline,
            COALESCE(SUM(' . Olts::weakSql('o', 't.rx_warning') . '),0) AS weak FROM olt_onus o JOIN olts t ON t.id=o.olt_id WHERE 1=1' . $scope, $scopeParams) ?? [];
        $waiting = [];
        foreach ($olts as $o) {
            if ($oltId && (int) $o['id'] !== $oltId) {
                continue;
            }
            foreach ((OltInventory::saved($o)['waiting'] ?? []) as $w) {
                if (($port !== '' && ($w['position'] ?? '') !== $port) || ($board !== '' && !str_starts_with((string) ($w['position'] ?? ''), $board . '/'))) {
                    continue;
                }
                $waiting[] = ['sn' => (string) ($w['sn'] ?? ''), 'position' => (string) ($w['position'] ?? ''), 'model' => (string) ($w['model'] ?? ''),
                    'oltId' => (int) $o['id'], 'olt' => (string) $o['name'], 'canAdd' => Auth::can('nas.manage') && OltManager::canChange($o)];
            }
        }

        $where = ' WHERE 1=1' . $scope;
        $params = $scopeParams;
        if ($status === 'online' || $status === 'offline') {
            $where .= ' AND o.status=?';
            $params[] = $status;
        } elseif ($status === 'weak') {
            $where .= ' AND ' . Olts::weakSql('o', 't.rx_warning');
        }
        // What each filter offers: only values that exist, cards and ports narrowed by the OLT and card chosen.
        $in = $oltId ? ' WHERE olt_id=' . $oltId : '';
        $column = static fn (string $sql): array => array_values(array_filter(array_map(static fn (array $r) => (string) reset($r), Db::select($sql)), static fn (string $v) => $v !== ''));
        $choices = [
            'boards' => $column('SELECT DISTINCT CONCAT(frame, "/", slot) FROM olt_onus' . $in . ' ORDER BY frame, slot'),
            'ports' => $board === '' ? [] : array_values(array_filter($column('SELECT DISTINCT CONCAT(frame, "/", slot, "/", port) FROM olt_onus' . $in . ' ORDER BY frame, slot, port'), static fn (string $p) => str_starts_with($p, $board . '/'))),
            'vlans' => array_map('intval', $column('SELECT DISTINCT vlan FROM olt_macs' . $in . ' ORDER BY vlan')),
            'types' => $column('SELECT DISTINCT onu_model FROM olt_onus' . $in . ' ORDER BY onu_model'),
            'profiles' => $column('SELECT DISTINCT service_profile FROM olt_onus' . $in . ' ORDER BY service_profile'),
        ];
        $total = $status === 'waiting' ? 0 : (int) Db::value('SELECT COUNT(*) FROM olt_onus o JOIN olts t ON t.id=o.olt_id' . $where, $params);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($pages, (int) $request->query('page', 1)));
        $rows = $status === 'waiting' ? [] : Db::select('SELECT o.*, t.name AS olt_name, t.rx_warning, t.rx_critical,
            (SELECT GROUP_CONCAT(c.username ORDER BY c.username SEPARATOR "\n") FROM customer_onus l JOIN customer_profiles c ON c.id=l.customer_id WHERE l.olt_id=o.olt_id AND l.sn=o.sn) AS clients
            FROM olt_onus o JOIN olts t ON t.id=o.olt_id' . $where . ' ORDER BY t.name,o.frame,o.slot,o.port,o.ont_id LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $params);

        return Inertia::render('olts/Onus', [
            'olts' => array_map(static fn (array $o) => ['id' => (int) $o['id'], 'name' => (string) $o['name']], $olts),
            'counts' => array_map('intval', $counts) + ['waiting' => count($waiting)],
            'filters' => ['q' => $q, 'status' => $status, 'olt' => $oltId, 'board' => $board, 'port' => $port, 'vlan' => $vlan > 0 ? $vlan : 0, 'type' => $type, 'profile' => $profile, 'linked' => $linked],
            'choices' => $choices,
            'waiting' => $waiting,
            'result' => ['rows' => array_map(static fn (array $r) => OltController::onuRow($r) + [
                'oltId' => (int) $r['olt_id'], 'olt' => (string) $r['olt_name'], 'weak' => (float) $r['rx_warning'], 'veryWeak' => (float) $r['rx_critical'],
            ], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'size' => self::PAGE_SIZE],
        ]);
    }

    /**
     * The ONUs before and after this one, in the order of the list (OLT, then port, then number), so the page can step
     * from one ONU to the next without going back to the list.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $row
     * @return array{previous: ?array<string, mixed>, next: ?array<string, mixed>}
     */
    private static function neighbours(array $olt, array $row): array
    {
        $at = [(int) $row['frame'], (int) $row['slot'], (int) $row['port'], (int) $row['ont_id']];
        $link = static fn (?array $o) => $o === null ? null : ['href' => '/olts/' . (int) $o['olt_id'] . '/onus/' . (int) $o['id'], 'name' => Olts::onuName((string) $o['description']) ?: (string) $o['sn'], 'position' => Olts::position($o)];
        $step = static function (string $compare, string $order) use ($olt, $at): ?array {
            return Db::row("SELECT id,olt_id,sn,description,frame,slot,port,ont_id FROM olt_onus WHERE olt_id=? AND (frame,slot,port,ont_id) $compare (?,?,?,?) ORDER BY frame $order,slot $order,port $order,ont_id $order LIMIT 1", [$olt['id'], ...$at]);
        };
        // At either end of an OLT, on to the neighbouring OLT by name.
        $other = static function (string $compare, string $order) use ($olt): ?array {
            $o = Db::row("SELECT id FROM olts WHERE name $compare ? ORDER BY name $order LIMIT 1", [$olt['name']]);
            return $o === null ? null : Db::row("SELECT id,olt_id,sn,description,frame,slot,port,ont_id FROM olt_onus WHERE olt_id=? ORDER BY frame $order,slot $order,port $order,ont_id $order LIMIT 1", [$o['id']]);
        };
        return ['previous' => $link($step('<', 'DESC') ?? $other('<', 'DESC')), 'next' => $link($step('>', 'ASC') ?? $other('>', 'ASC'))];
    }

    public function show(string $id, string $onu): Response
    {
        [$olt, $row] = self::find($id, $onu);
        $clients = Db::select('SELECT c.username,c.first_name,c.last_name,c.company_name,c.address,c.city,c.phone,c.cell_phone FROM customer_onus l JOIN customer_profiles c ON c.id=l.customer_id WHERE l.olt_id=? AND l.sn=? AND l.source<>"none" ORDER BY c.username', [$olt['id'], $row['sn']]);
        return Inertia::render('olts/Onu', [
            'olt' => ['id' => (int) $olt['id'], 'name' => (string) $olt['name'], 'canRead' => OltManager::canRead($olt), 'canChange' => OltManager::canChange($olt), 'rxWarning' => (float) ($olt['rx_warning'] ?? -27), 'rxCritical' => (float) ($olt['rx_critical'] ?? -30)],
            'disconnections' => OltMonitor::disconnections((int) $olt['id'], (string) $row['sn']),
            'history' => OltChangelog::forOnu((int) $olt['id'], (string) $row['sn']),
            'addedAt' => OltChangelog::addedAt((int) $olt['id'], (string) $row['sn']),
            'timeline' => OltMonitor::timeline((int) $olt['id'], (string) $row['sn']),
            // How long the ONU has been in its current state, worked out here so the browser's clock and timezone do not matter.
            'since' => (static function () use ($row): ?int {
                $at = $row['status'] === 'online' ? $row['last_up_at'] : ($row['status'] === 'offline' ? $row['last_down_at'] : null);
                $time = $at ? strtotime((string) $at) : false;
                return $time === false ? null : max(0, time() - $time);
            })(),
            'trafficTables' => OltInventory::saved($olt)['trafficTables'] ?? [],
            'ranges' => array_map(static fn (array $r) => $r[2], OltMonitor::RANGES),
            'onu' => OltController::onuRow($row + ['clients' => implode("\n", array_column($clients, 'username'))]),
            'neighbours' => self::neighbours($olt, $row),
            'clients' => array_map(static fn (array $c) => [
                'username' => (string) $c['username'], 'name' => trim($c['first_name'] . ' ' . $c['last_name']) ?: (string) $c['company_name'], 'address' => trim($c['address'] . ' ' . $c['city']), 'phone' => trim((string) ($c['cell_phone'] ?: $c['phone'])),
                // Whether the client's RADIUS login can be pushed to the ONU for PPPoE. The password itself never leaves the server.
                'pppoe' => self::radiusLogin((string) $c['username']) !== null,
            ], $clients),
        ]);
    }

    /** How long one read of an ONU serves its page, its tabs and a reload: each read is a telnet login on the OLT. */
    private const LIVE_SECONDS = 30;
    /** The OLT's VLANs, profiles and traffic tables change rarely, and the portal forgets them when it changes them. */
    private const CHOICES_SECONDS = 3600;

    /** @param  array<string, mixed>  $row */
    private static function liveKey(array $row): string
    {
        return 'onu-live:' . (int) $row['olt_id'] . ':' . (int) $row['id'] . ':' . $row['sn'];
    }

    /**
     * The last read of this ONU if it is recent, else a new one. ?fresh=1 (Get status, Live) always asks the OLT.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $row
     * @return array{at:int,data:array<mixed>}
     */
    private static function liveRead(array $olt, array $row, bool $fresh, int $within = self::LIVE_SECONDS): array
    {
        $cached = $fresh ? null : Cache::get(self::liveKey($row));
        if (is_array($cached) && is_int($cached['at'] ?? null) && is_array($cached['data'] ?? null) && time() - $cached['at'] <= $within) {
            return ['at' => $cached['at'], 'data' => $cached['data']];
        }
        $read = ['at' => time(), 'data' => OltManager::live($olt, $row)];
        // Kept for a week: a page opened later shows this at once, says how old it is, and asks the OLT again.
        Cache::put(self::liveKey($row), $read, 604800);
        return $read;
    }

    /** Asks the OLT, or answers from a read of the last 30 seconds. Only reads. */
    public function live(Request $request, string $id, string $onu): JsonResponse
    {
        [$olt, $row] = self::find($id, $onu);
        set_time_limit(90);
        try {
            // "quick" is the page opening: it gets the last read at once, however old, and asks for a new one itself
            // when that is more than 30 seconds old.
            $read = self::liveRead($olt, $row, $request->boolean('fresh'), $request->boolean('quick') ? 604800 : self::LIVE_SECONDS);
            // Traffic comes from the regular reads over SNMP: the average of the last 5 minutes, in kbit/s. Asking the
            // OLT to measure it for one ONU takes over 4 seconds.
            $sample = Db::row('SELECT up_bps,down_bps FROM olt_onu_samples WHERE olt_id=? AND sn=? AND sampled_at>=? AND down_bps IS NOT NULL ORDER BY sampled_at DESC LIMIT 1', [$olt['id'], $row['sn'], time() - 900]);
            $read['data']['traffic'] = ['up' => $sample ? (int) round((float) $sample['up_bps'] / 1000) : null, 'down' => $sample ? (int) round((float) $sample['down_bps'] / 1000) : null];
            $payload = ['ok' => true, 'data' => $read['data'], 'age' => time() - $read['at']];
        } catch (RuntimeException $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /** The ONU's part of the OLT's configuration. Only reads. */
    public function config(Request $request, string $id, string $onu): JsonResponse
    {
        [$olt, $row] = self::find($id, $onu);
        set_time_limit(90);
        try {
            // The page's own read has it, unless Read again asked for a new one.
            $cached = $request->boolean('fresh') ? null : Cache::get(self::liveKey($row));
            $text = is_array($cached) && time() - (int) $cached['at'] <= 60 && ($cached['data']['config'] ?? '') !== '' ? (string) $cached['data']['config'] : null;
            $payload = ['ok' => true, 'output' => $text ?? OltManager::runningConfig($olt, $row)];
        } catch (RuntimeException $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /** What the OMCI settings can be chosen from, read from the OLT. Only reads. */
    public function omciOptions(string $id, string $onu): JsonResponse
    {
        [$olt, $row] = self::find($id, $onu);
        set_time_limit(120);
        try {
            $choices = Cache::remember('olt-omci-choices:' . (int) $olt['id'], self::CHOICES_SECONDS, static fn () => OltManager::omciChoices($olt));
            // What the ONU has set comes with the page's own read, which the page also uses itself: no second login.
            $cached = Cache::get(self::liveKey($row));
            $state = is_array($cached) && is_array($cached['data']['omci'] ?? null) ? $cached['data']['omci'] : OltManager::onuState($olt, $row);
            $payload = ['ok' => true, 'data' => $choices + ['state' => $state]];
        } catch (RuntimeException $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /** @return ?array{0:string,1:string} a client's RADIUS username and password, when both fit on the OLT's command line */
    private static function radiusLogin(string $username): ?array
    {
        $password = (string) (Db::value('SELECT value FROM radcheck WHERE username=? AND attribute="Cleartext-Password" ORDER BY id DESC LIMIT 1', [$username]) ?: '');
        return HuaweiCli::isCredential($username) && HuaweiCli::isCredential($password) ? [$username, $password] : null;
    }

    /** The ONU's traffic or signal over time. Nothing is asked of the OLT: it comes from the regular reads. */
    public function graph(Request $request, string $id, string $onu): JsonResponse
    {
        [$olt, $row] = self::find($id, $onu);
        $range = is_string($request->query('range')) && isset(OltMonitor::RANGES[$request->query('range')]) ? $request->query('range') : '24h';
        $chart = $request->query('chart') === 'signal' ? 'onu-signal' : 'onu-traffic';
        return response()->json(MonitoringData::oltHardwareChart($olt, $chart, $range, ['sn' => (string) $row['sn']]))->header('Cache-Control', 'no-store');
    }

    /** A service port's speed ceiling, or a LAN port off or on. */
    public function setting(Request $request, string $id, string $onu, string $what, string $number): RedirectResponse
    {
        [$olt, $row] = self::find($id, $onu);
        set_time_limit(120);
        try {
            if ($what === 'eth') {
                $native = trim((string) $request->input('native', ''));
                $tagged = $request->input('tagged', []);
                $message = OltManager::ethPort($olt, $row, (int) $number, (string) $request->input('mode', ''), $native === '' ? null : (int) $native, is_array($tagged) ? array_values(array_filter(array_map('intval', $tagged))) : []);
            } elseif ($what === 'lan-vlan') {
                OltManager::lanVlan($olt, $row, (int) $number, (int) $request->input('vlan', 0), (int) $request->input('priority', 0));
                $message = 'LAN port ' . (int) $number . ' now puts untagged traffic on VLAN ' . (int) $request->input('vlan', 0) . '.';
            } elseif ($what === 'service-ports') {
                OltManager::servicePortSpeed($olt, $row, (int) $number, (int) $request->input('up', -1), (int) $request->input('down', -1));
                $message = 'Service port ' . (int) $number . ' now uses the chosen traffic tables.';
            } else {
                $on = (string) $request->input('state') === 'on';
                OltManager::lanPort($olt, $row, (int) $number, $on);
                $message = 'LAN port ' . (int) $number . ' is turned ' . ($on ? 'on' : 'off') . '.';
            }
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        Cache::forget(self::liveKey($row));
        return back()->with('flash', ['type' => 'success', 'message' => $message]);
    }

    public function change(Request $request, string $id, string $onu, string $action): RedirectResponse
    {
        [$olt, $row] = self::find($id, $onu);
        if (in_array($action, ['delete', 'factory-reset'], true)) {
            Auth::requireCan('danger.delete');
        }
        set_time_limit(120);
        $where = Olts::position($row) . ' (' . $row['sn'] . ')';
        try {
            $message = match ($action) {
                'reboot' => (static function () use ($olt, $row, $where) {
                    OltManager::reboot($olt, $row);
                    return 'ONU ' . $where . ' is restarting. It is back in a minute or two.';
                })(),
                'disable' => (static function () use ($olt, $row, $where) {
                    OltManager::setActive($olt, $row, false);
                    return 'ONU ' . $where . ' is turned off. Its settings stay on the OLT.';
                })(),
                'enable' => (static function () use ($olt, $row, $where) {
                    OltManager::setActive($olt, $row, true);
                    return 'ONU ' . $where . ' is turned on. It comes online within a minute.';
                })(),
                'factory-reset' => (static function () use ($olt, $row, $where) {
                    OltManager::factoryReset($olt, $row);
                    return 'ONU ' . $where . ' is going back to its factory settings and restarting. The OLT sets its internet connection up again by itself.';
                })(),
                'wan' => (static function () use ($olt, $row, $request) {
                    $login = null;
                    $client = trim((string) $request->input('pppoe_client', ''));
                    if ($request->input('mode') === 'pppoe' && $client !== '') {
                        // Only a client linked to this ONU, and only its own RADIUS login.
                        $linked = Db::value('SELECT 1 FROM customer_onus l JOIN customer_profiles c ON c.id=l.customer_id WHERE l.olt_id=? AND l.sn=? AND l.source<>"none" AND c.username=?', [$olt['id'], $row['sn'], $client]);
                        $login = $linked ? self::radiusLogin($client) : null;
                        if ($login === null) {
                            throw new RuntimeException($linked ? 'The RADIUS login of ' . $client . ' has characters the OLT cannot take. Type the PPPoE login instead.' : $client . ' is not linked to this ONU.');
                        }
                    }
                    return OltManager::wanConfig($olt, $row, $request->only(['mode', 'ip_index', 'vlan', 'priority', 'wan_profile', 'route_profile', 'route_lan', 'pppoe_user', 'pppoe_password', 'ip', 'mask', 'gateway', 'dns1', 'dns2']), $login);
                })(),
                'bridge' => OltManager::bridge($olt, $row, (int) $request->input('vlan', 0)),
                'add-speed' => (static function () use ($olt, $request) {
                    $table = OltManager::addSpeed($olt, (int) $request->input('mbps', 0));
                    return 'The OLT now has ' . (int) $request->input('mbps', 0) . 'M as traffic table ' . $table['id'] . '. Choose it under Configure.';
                })(),
                'describe' => 'The description on the OLT is now "' . OltManager::describe($olt, $row, (string) $request->input('description', '')) . '".',
                'delete' => (static function () use ($olt, $row, $where) {
                    OltManager::delete($olt, $row);
                    return 'ONU ' . $where . ' was removed from the OLT, with its service ports.';
                })(),
                default => throw new RuntimeException('Unknown action.'),
            };
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        Cache::forget(self::liveKey($row));
        if ($action === 'add-speed') {
            Cache::forget('olt-omci-choices:' . (int) $olt['id']);
        }
        return $action === 'delete' ? $this->ok('/olts/' . (int) $olt['id'], $message) : back()->with('flash', ['type' => 'success', 'message' => $message]);
    }

    /** The page that lists ONUs plugged in but not yet added. Opening it asks the OLT. */
    public function provision(Request $request, string $id): Response
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        set_time_limit(150);
        $options = null;
        $error = '';
        try {
            $options = OltManager::options($olt);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        $username = trim((string) $request->query('client', ''));
        $client = $username === '' ? null : Db::row('SELECT username,first_name,last_name,company_name FROM customer_profiles WHERE username=?', [$username]);
        return Inertia::render('olts/Provision', [
            'olt' => ['id' => (int) $olt['id'], 'name' => (string) $olt['name'], 'canChange' => OltManager::canChange($olt)],
            'options' => $options,
            'error' => $error,
            'client' => $client ? ['username' => (string) $client['username'], 'description' => HuaweiCli::description((trim($client['first_name'] . ' ' . $client['last_name']) ?: (string) $client['company_name']) . ' ' . $client['username'])] : null,
        ]);
    }

    public function add(Request $request, string $id): RedirectResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $username = trim((string) $request->input('client', ''));
        $client = null;
        if ($username !== '') {
            Auth::requireCan('clients.manage');
            $client = Db::row('SELECT * FROM customer_profiles WHERE username=?', [$username]);
            if (!$client || Clients::isArchived($client)) {
                return $this->fail($client ? 'This client is archived.' : 'There is no client "' . $username . '". Leave the client empty to add the ONU without one.');
            }
        }
        set_time_limit(150);
        try {
            $onu = OltManager::authorize($olt, $request->only(['sn', 'mode', 'line_profile', 'service_profile', 'vlan', 'gem', 'traffic_up', 'traffic_down', 'wan_profile', 'route_profile', 'description']));
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        $message = 'ONU ' . $onu['sn'] . ' was added as ' . Olts::position($onu) . '.';
        if ($client) {
            try {
                OltMapper::linkBySerial((int) $client['id'], (string) $client['username'], (string) $onu['sn'], (string) (Auth::user()['username'] ?? ''));
                $message .= ' It is linked to ' . $client['username'] . '.';
            } catch (RuntimeException $e) {
                $message .= ' It could not be linked to ' . $client['username'] . ': ' . $e->getMessage();
            }
        }
        return $this->ok('/olts/' . (int) $olt['id'] . '/onus/' . (int) $onu['id'], $message . ' It comes online within a minute.');
    }
}
