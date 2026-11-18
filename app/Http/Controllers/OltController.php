<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Clients;
use App\Http\Support\Csv;
use App\Http\Support\Format;
use App\Http\Support\MonitoringData;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\Db;
use App\Support\Olt\OltConsole;
use App\Support\Olt\OltConsoleUnavailable;
use App\Support\Olt\OltInventory;
use App\Support\Olt\OltManager;
use App\Support\Olt\OltMapper;
use App\Support\Olt\OltMonitor;
use App\Support\Olt\Olts;
use App\Support\Snmp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** OLTs, their ONUs and which client is on which ONU. Only reads from the OLT: changes are in OnuController. */
final class OltController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(): Response
    {
        return Inertia::render('olts/Index', [
            'olts' => array_map(static fn (array $o) => [
                'id' => (int) $o['id'],
                'name' => (string) $o['name'],
                'model' => Olts::MODELS[$o['model']] ?? (string) $o['model'],
                'host' => (string) $o['host'],
                'enabled' => (bool) $o['enabled'],
                'onus' => (int) $o['onus'],
                'online' => (int) $o['online'],
                'lastSync' => $o['last_sync_at'],
                'ok' => $o['last_sync_ok'] === null ? null : (bool) $o['last_sync_ok'] && $o['last_mac_ok'] !== 0 && $o['last_mac_ok'] !== '0',
                'error' => (string) ($o['last_error'] ?: $o['last_mac_error']),
            ], Db::select('SELECT t.*,COUNT(o.id) AS onus,COALESCE(SUM(o.status="online"),0) AS online FROM olts t LEFT JOIN olt_onus o ON o.olt_id=t.id GROUP BY t.id ORDER BY t.name')),
        ]);
    }

    public function form(?string $id = null): Response
    {
        $olt = $id === null ? null : Olts::find((int) $id);
        abort_if($id !== null && !$olt, 404, 'OLT not found.');
        return Inertia::render('olts/Form', [
            'id' => $olt ? (int) $olt['id'] : null,
            'models' => Olts::MODELS,
            'authOptions' => ['none' => 'None'] + array_combine(array_keys(Snmp::AUTH_PROTOCOLS), array_map('strtoupper', array_keys(Snmp::AUTH_PROTOCOLS))),
            'privOptions' => ['none' => 'None', 'aes128' => 'AES-128', 'des' => 'DES'],
            'values' => [
                'name' => (string) ($olt['name'] ?? ''),
                'model' => (string) ($olt['model'] ?? 'huawei_ma5800_x7'),
                'host' => (string) ($olt['host'] ?? ''),
                'enabled' => $olt ? (bool) $olt['enabled'] : true,
                'snmp_version' => (string) ($olt['snmp_version'] ?? '2c'),
                'snmp_port' => (string) ($olt['snmp_port'] ?? '161'),
                'snmp_community' => '',
                'v3_user' => (string) ($olt['v3_user'] ?? ''),
                'v3_context' => (string) ($olt['v3_context'] ?? ''),
                'v3_auth_protocol' => (string) ($olt['v3_auth_protocol'] ?? 'sha1'),
                'v3_auth_password' => '',
                'v3_priv_protocol' => (string) ($olt['v3_priv_protocol'] ?? 'aes128'),
                'v3_priv_password' => '',
                'telnet_enabled' => (bool) ($olt['telnet_enabled'] ?? false),
                'telnet_port' => (string) ($olt['telnet_port'] ?? '23'),
                'telnet_user' => '',
                'telnet_password' => '',
                'manage_enabled' => (bool) ($olt['manage_enabled'] ?? false),
                'rx_warning' => (string) (float) ($olt['rx_warning'] ?? -27),
                'rx_critical' => (string) (float) ($olt['rx_critical'] ?? -30),
            ],
            // Secrets go one way: the page is only told whether one is saved.
            'saved' => [
                'snmp_community' => !empty($olt['snmp_community']),
                'v3_auth_password' => !empty($olt['v3_auth_password']),
                'v3_priv_password' => !empty($olt['v3_priv_password']),
                'telnet_user' => !empty($olt['telnet_user']),
                'telnet_password' => !empty($olt['telnet_password']),
            ],
        ]);
    }

    public function save(Request $request, ?string $id = null): RedirectResponse
    {
        $stored = $id === null ? null : Olts::find((int) $id);
        abort_if($id !== null && !$stored, 404, 'OLT not found.');
        $back = $stored ? '/olts/' . (int) $id . '/edit' : '/olts/create';
        try {
            $values = Olts::validated($this->formInput($request), $stored);
            $saved = Olts::save($stored ? (int) $id : null, $values);
        } catch (PDOException|RuntimeException $e) {
            return $this->failKeepingInput($e, $back, ['name' => '/^Name/', 'host' => '/address|in use/', 'snmp_community' => '/community/', 'telnet_user' => '/Telnet/']);
        }
        // Reads the OLT with the new settings on the next minute.
        Db::exec('UPDATE olts SET sync_requested_at=NOW() WHERE id=? AND enabled=1', [$saved]);
        $public = static fn (array $o) => array_intersect_key($o, array_flip(['name', 'model', 'host', 'enabled', 'snmp_version', 'snmp_port', 'telnet_enabled', 'telnet_port', 'manage_enabled', 'rx_warning', 'rx_critical']));
        Audit::log($stored ? 'update' : 'create', 'olt', (string) $saved, $stored ? $public($stored) : null, $public($values));
        return $this->ok('/olts/' . $saved, 'OLT saved. It is read within a minute. ' . ($values['manage_enabled'] ? 'Changes from the portal are allowed for this OLT.' : 'Nothing is changed on the OLT.'));
    }

    public function test(string $id): RedirectResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $result = Olts::test($olt);
        $message = implode(' ', $result['lines']);
        return $result['ok'] ? $this->ok('/olts/' . (int) $id . '/edit', $message) : $this->fail($message, '/olts/' . (int) $id . '/edit');
    }

    public function sync(string $id): RedirectResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        if (!$olt['enabled']) {
            return $this->fail('This OLT is turned off. Turn it on under Edit first.', '/olts/' . (int) $id);
        }
        Db::exec('UPDATE olts SET sync_requested_at=NOW() WHERE id=?', [$olt['id']]);
        return $this->ok('/olts/' . (int) $id, 'The OLT is read within a minute. Refresh this page to see it.');
    }

    public function delete(string $id): RedirectResponse
    {
        Auth::requireCan('danger.delete');
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        Db::exec('DELETE FROM olts WHERE id=?', [$olt['id']]);
        Audit::log('delete', 'olt', (string) $olt['id'], ['name' => $olt['name'], 'host' => $olt['host']]);
        return $this->ok('/olts', 'OLT removed from the portal, with its ONU list and client links. Nothing was changed on the OLT.');
    }

    public function show(Request $request, string $id): Response
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $where = ' WHERE o.olt_id=?';
        $params = [$olt['id']];
        if (in_array($status, ['online', 'offline'], true)) {
            $where .= ' AND o.status=?';
            $params[] = $status;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (o.sn LIKE ? OR o.description LIKE ? OR EXISTS (SELECT 1 FROM customer_onus l JOIN customer_profiles c ON c.id=l.customer_id WHERE l.olt_id=o.olt_id AND l.sn=o.sn AND c.username LIKE ?))';
            array_push($params, $like, $like, $like);
        }
        $total = (int) Db::value('SELECT COUNT(*) FROM olt_onus o' . $where, $params);
        [$page, $pages, $offset] = self::page($request, $total);
        $rows = Db::select('SELECT o.*,(SELECT GROUP_CONCAT(c.username ORDER BY c.username SEPARATOR "\n") FROM customer_onus l JOIN customer_profiles c ON c.id=l.customer_id WHERE l.olt_id=o.olt_id AND l.sn=o.sn) AS clients
            FROM olt_onus o' . $where . ' ORDER BY o.frame,o.slot,o.port,o.ont_id LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset, $params);
        $counts = Db::row('SELECT COUNT(*) AS total,COALESCE(SUM(status="online"),0) AS online,COALESCE(SUM(status="offline"),0) AS offline,COALESCE(SUM(rx_dbm<? OR olt_rx_dbm<?),0) AS weak FROM olt_onus WHERE olt_id=?', [$olt['rx_warning'] ?? -27, $olt['rx_warning'] ?? -27, $olt['id']]);
        return Inertia::render('olts/Show', [
            'olt' => [
                'id' => (int) $olt['id'], 'name' => (string) $olt['name'], 'model' => Olts::MODELS[$olt['model']] ?? (string) $olt['model'], 'host' => (string) $olt['host'], 'enabled' => (bool) $olt['enabled'],
                'telnet' => (bool) $olt['telnet_enabled'], 'canChange' => OltManager::canChange($olt), 'rxWarning' => (float) ($olt['rx_warning'] ?? -27), 'rxCritical' => (float) ($olt['rx_critical'] ?? -30), 'lastSync' => $olt['last_sync_at'], 'lastMacSync' => $olt['last_mac_sync_at'],
                'syncRequested' => $olt['sync_requested_at'] !== null,
                'error' => (string) ($olt['last_error'] ?? ''), 'macError' => (string) ($olt['last_mac_error'] ?? ''),
            ],
            'counts' => array_map('intval', $counts ?? []),
            'filters' => ['q' => $q, 'status' => $status],
            'hardware' => self::hardware($olt),
            'details' => self::details($olt),
            'backups' => Auth::can('nas.manage') ? array_map(static fn (array $b) => ['id' => (int) $b['id'], 'taken' => (string) $b['taken_at'], 'checked' => (string) $b['checked_at'], 'bytes' => (int) $b['bytes']], Db::select('SELECT id,taken_at,checked_at,bytes FROM olt_config_backups WHERE olt_id=? ORDER BY taken_at DESC,id DESC', [$olt['id']])) : null,
            'history' => array_map(static fn (array $a) => ['at' => (string) $a['created_at'], 'who' => (string) $a['actor_username'], 'action' => (string) $a['action'], 'what' => $a['entity_type'] === 'onu' ? 'ONU ' . $a['entity_id'] : 'OLT', 'detail' => implode('; ', (array) (json_decode((string) $a['after_json'], true)['commands'] ?? []))],
                Db::select('SELECT created_at,actor_username,action,entity_type,entity_id,after_json FROM audit_logs WHERE (entity_type="olt" AND entity_id=?) OR (entity_type="onu" AND after_json LIKE ?) ORDER BY id DESC LIMIT 200', [(string) $olt['id'], '%' . addcslashes(substr((string) json_encode(['olt' => (string) $olt['name']], JSON_UNESCAPED_SLASHES), 1, -1), '%_\\') . '%'])),
            'graphs' => (static function () use ($olt, $request): array {
                $range = self::range($request->query('range'));
                $graph = static fn (string $key, string $title, array $data) => ['key' => $key, 'title' => $title, 'url' => '/olts/' . (int) $olt['id'] . '/graph?chart=' . $key . '&range=' . $range, 'data' => $data];

                return ['range' => $range, 'ranges' => array_map(static fn (array $r) => $r[2], OltMonitor::RANGES), 'charts' => [
                    $graph('onus', 'ONUs over time', MonitoringData::oltChart($olt, $range)),
                    $graph('cpu', 'Control card CPU and memory', MonitoringData::oltHardwareChart($olt, 'cpu', $range)),
                    $graph('temperature', 'Temperature', MonitoringData::oltHardwareChart($olt, 'temperature', $range)),
                    $graph('traffic', 'Uplink traffic', MonitoringData::oltHardwareChart($olt, 'traffic', $range)),
                ]];
            })(),
            'result' => ['rows' => array_map(self::onuRow(...), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'size' => self::PAGE_SIZE],
        ]);
    }

    /**
     * The OLT itself: what it is, how long it has been up, and what its command line said at the last read.
     *
     * @param  array<string, mixed>  $olt
     * @return array<string, mixed>
     */
    private static function details(array $olt): array
    {
        $inventory = OltInventory::saved($olt);
        $temperature = Db::value('SELECT MAX(temperature_c) FROM olt_boards WHERE olt_id=?', [$olt['id']]);
        return [
            'description' => (string) ($olt['system_description'] ?? ''),
            'version' => $inventory['version'] ?? null,
            'bootedAt' => $olt['booted_at'] ?? null,
            'power' => ($olt['power_w'] ?? null) === null ? null : (int) $olt['power_w'],
            'temperature' => $temperature === null ? null : (int) $temperature,
            'snmp' => 'v' . $olt['snmp_version'] . ', UDP ' . $olt['snmp_port'],
            'telnetPort' => $olt['telnet_enabled'] ? (int) $olt['telnet_port'] : null,
            'ntp' => $inventory['ntp'] ?? [],
            'autosave' => $inventory['autosave'] ?? null,
            'vlans' => $inventory['vlans'] ?? [],
            'lineProfiles' => $inventory['lineProfiles'] ?? [], 'serviceProfiles' => $inventory['serviceProfiles'] ?? [],
            'dbaProfiles' => $inventory['dbaProfiles'] ?? [], 'trafficTables' => $inventory['trafficTables'] ?? [],
            'read' => $olt['last_inventory_at'] ?? null,
            'error' => (string) ($olt['last_inventory_error'] ?? ''),
            'backupAt' => $olt['last_backup_at'] ?? null,
            'backupError' => (string) ($olt['last_backup_error'] ?? ''),
        ];
    }

    /** Every MAC the OLT last listed behind an ONU, with the ONU and its client, as a CSV file. */
    public function macs(string $id): HttpResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $rows = static fn () => Db::select('SELECT m.mac,m.vlan,m.frame,m.slot,m.port,m.ont_id,m.seen_at,o.sn,o.description,
            (SELECT GROUP_CONCAT(c.username ORDER BY c.username SEPARATOR " ") FROM customer_onus l JOIN customer_profiles c ON c.id=l.customer_id WHERE l.olt_id=o.olt_id AND l.sn=o.sn AND l.source<>"none") AS clients
            FROM olt_macs m LEFT JOIN olt_onus o ON o.olt_id=m.olt_id AND o.frame=m.frame AND o.slot=m.slot AND o.port=m.port AND o.ont_id=m.ont_id WHERE m.olt_id=? ORDER BY m.frame,m.slot,m.port,m.ont_id,m.mac', [$olt['id']]);
        return Csv::download(preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $olt['name']) . '-mac-table.csv', ['MAC', 'VLAN', 'Port', 'ONU serial', 'ONU description', 'Client', 'Read at'],
            $rows, static fn (array $r) => [OltMapper::formatMac((string) $r['mac']), $r['vlan'], Olts::position($r), (string) $r['sn'], (string) $r['description'], (string) $r['clients'], (string) $r['seen_at']]);
    }

    /** One "display" command, run on the OLT now. OltCli lets nothing else through. */
    public function cli(Request $request, string $id): JsonResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $command = trim((string) preg_replace('/\s+/', ' ', (string) $request->query('command', '')));
        set_time_limit(150);
        try {
            if (!OltManager::canRead($olt)) {
                throw new RuntimeException('The command line needs telnet turned on for this OLT, under Edit.');
            }
            if (!str_starts_with($command, 'display ')) {
                throw new RuntimeException('Only commands that start with "display" can be run here. They read the OLT and change nothing.');
            }
            $output = str_replace("\r", '', Olts::cli($olt, 120.0)->read([$command])[0]);
            Audit::log('cli', 'olt', (string) $olt['id'], null, ['commands' => [$command]]);
            $payload = ['ok' => true, 'output' => mb_substr(mb_scrub($output, 'UTF-8'), 0, 400000)];
        } catch (RuntimeException $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage() === 'This command is not allowed on the OLT.' ? 'Only plain "display" commands are allowed: letters, digits, spaces, "/" and "-".' : $e->getMessage()];
        }
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /**
     * The OLT's live console (OltConsole): open a session, pass keys in and output out, close it. The same people as the
     * terminal below may use it; each line typed is written to the audit log under the operator's own name.
     */
    public function console(Request $request, string $id, ?string $session = null, ?string $action = null): JsonResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        Auth::requireCan('danger.delete');
        abort_if($session !== null && !preg_match('/^[a-f0-9]{32}$/D', $session), 404);
        $user = (int) (Auth::user()['id'] ?? 0);
        $base = ['olt' => (int) $olt['id'], 'user' => $user];
        try {
            if ($session === null) {
                if (!OltManager::canChange($olt)) {
                    throw new RuntimeException('The console needs Allow changes from the portal turned on for this OLT, under Edit.');
                }
                $answer = OltConsole::request(['op' => 'open', 'cols' => $request->integer('cols', 80), 'rows' => $request->integer('rows', 24)] + $base);
                Audit::log('olt_console', 'olt', (string) $olt['id'], null, ['olt' => (string) $olt['name'], 'commands' => ['(console session opened)']]);
                $payload = ['ok' => true, 'session' => $answer['session']];
            } elseif ($action === 'close') {
                OltConsole::request(['op' => 'close', 'session' => $session] + $base);
                $payload = ['ok' => true];
            } else {
                $input = (string) $request->input('input', '');
                if (strlen($input) > 65536) {
                    throw new RuntimeException('Too much was pasted at once. Paste it in smaller parts.');
                }
                $answer = OltConsole::request(['op' => 'io', 'session' => $session, 'input' => $input, 'offset' => $request->integer('offset'), 'cols' => $request->integer('cols'), 'rows' => $request->integer('rows')] + $base);
                $lines = array_values(array_filter((array) ($answer['lines'] ?? []), 'is_string'));
                if ($lines) {
                    Audit::log('olt_terminal', 'olt', (string) $olt['id'], null, ['olt' => (string) $olt['name'], 'console' => true, 'commands' => array_map(OltManager::maskSecrets(...), $lines)]);
                }
                unset($answer['lines']);
                $payload = $answer;
            }
        } catch (OltConsoleUnavailable $e) {
            $payload = ['ok' => false, 'unavailable' => true, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage(), 'ended' => str_contains($e->getMessage(), 'has ended')];
        }
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /**
     * The OLT's terminal: any command, for operators who may delete, on an OLT that allows changes. Every command is in
     * the audit log. Careful commands and the OLT's own yes/no questions come back to be confirmed first.
     */
    public function terminal(Request $request, string $id): JsonResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        Auth::requireCan('danger.delete');
        $context = $request->input('context', []);
        set_time_limit(170);
        try {
            if (!is_array($context) || array_filter($context, static fn ($c) => !is_string($c))) {
                throw new RuntimeException('Type "return" to start again at the top.');
            }
            $payload = ['ok' => true] + OltManager::terminal($olt, array_values($context), (string) $request->input('command', ''), $request->boolean('confirmed'), $request->boolean('yes'));
        } catch (RuntimeException $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /** The OLT's own settings the portal can change: its time servers, its VLANs, and the VLANs an uplink port carries. */
    public function setting(Request $request, string $id, string $what): RedirectResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $add = (string) $request->input('action') !== 'remove';
        if (!$add) {
            Auth::requireCan('danger.delete');
        }
        set_time_limit(120);
        try {
            $message = match ($what) {
                'ntp' => OltManager::ntp($olt, $add, trim((string) $request->input('server', ''))),
                'vlans' => OltManager::vlan($olt, $add, (int) $request->input('vlan', 0)),
                'uplink-vlans' => (static function () use ($olt, $request, $add) {
                    $port = Db::row('SELECT name FROM olt_ports WHERE olt_id=? AND if_index=? AND kind="uplink"', [$olt['id'], (int) $request->input('port', 0)]);
                    if (!$port || !preg_match('~(\d+)\s*/\s*(\d+)\s*/\s*(\d+)~', (string) $port['name'], $m)) {
                        throw new RuntimeException('Choose an uplink port.');
                    }
                    return OltManager::uplinkVlan($olt, (int) $m[1], (int) $m[2], (int) $m[3], $add, (int) $request->input('vlan', 0));
                })(),
                default => throw new RuntimeException('Unknown setting.'),
            };
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        return back()->with('flash', ['type' => 'success', 'message' => $message]);
    }

    /** Autofind on or off, restart every ONU, or turn the port off or on: for one PON port, as the OLT lists it. */
    public function port(string $id, string $ifIndex, string $action): RedirectResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $port = Db::row('SELECT name FROM olt_ports WHERE olt_id=? AND if_index=? AND kind="pon"', [$olt['id'], (int) $ifIndex]);
        abort_if(!$port || !preg_match('~(\d+)\s*/\s*(\d+)\s*/\s*(\d+)~', (string) $port['name'], $m), 404, 'PON port not found.');
        if (in_array($action, ['off', 'on'], true)) {
            Auth::requireCan('danger.delete');
        }
        set_time_limit(120);
        try {
            $message = OltManager::port($olt, (int) $m[1], (int) $m[2], (int) $m[3], $action);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        return back()->with('flash', ['type' => 'success', 'message' => $message]);
    }

    public function card(string $id, string $frame, string $slot): RedirectResponse
    {
        Auth::requireCan('danger.delete');
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $board = Db::row('SELECT frame,slot,name FROM olt_boards WHERE olt_id=? AND frame=? AND slot=?', [$olt['id'], (int) $frame, (int) $slot]);
        abort_if(!$board, 404, 'Card not found.');
        set_time_limit(120);
        try {
            OltManager::restartCard($olt, $board);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        return back()->with('flash', ['type' => 'success', 'message' => 'Card ' . $board['name'] . ' in slot ' . $board['frame'] . '/' . $board['slot'] . ' is restarting. Everything on it is offline for a few minutes.']);
    }

    /** Takes a copy of the configuration now. Only reads from the OLT. */
    public function backup(string $id): RedirectResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        if (!OltManager::canRead($olt)) {
            return $this->fail('Configuration copies need telnet turned on for this OLT, under Edit.');
        }
        set_time_limit(320);
        try {
            $changed = OltInventory::backup($olt);
        } catch (RuntimeException $e) {
            Db::exec('UPDATE olts SET last_backup_at=NOW(),last_backup_error=? WHERE id=?', [mb_substr($e->getMessage(), 0, 500), $olt['id']]);
            return $this->fail($e->getMessage());
        }
        Audit::log('backup', 'olt', (string) $olt['id'], null, ['changed' => $changed]);
        return back()->with('flash', ['type' => 'success', 'message' => $changed ? 'A copy of the configuration was saved.' : 'The configuration is the same as the last copy, so no new copy was needed.']);
    }

    /** One saved configuration, as a text file. It holds the OLT's own secrets, so only people who may manage the OLT get it. */
    public function backupFile(string $id, string $backup): HttpResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $row = Db::row('SELECT id,taken_at FROM olt_config_backups WHERE id=? AND olt_id=?', [(int) $backup, $olt['id']]);
        abort_if(!$row, 404, 'This copy no longer exists.');
        $text = OltInventory::backupText((int) $olt['id'], (int) $row['id']);
        abort_if($text === null, 409, 'This copy cannot be opened. The server\'s key has changed since it was saved.');
        Audit::log('backup_download', 'olt', (string) $olt['id'], null, ['backup' => (int) $row['id'], 'taken' => (string) $row['taken_at']]);
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $olt['name'] . '-' . str_replace([' ', ':'], ['-', ''], (string) $row['taken_at'])) . '.cfg';
        return response($text, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'no-store']);
    }

    /**
     * @param  array<string, mixed>  $olt
     * @return array<string, mixed>
     */
    private static function hardware(array $olt): array
    {
        $inventory = OltInventory::saved($olt);
        $signals = [];
        foreach (Db::select('SELECT frame,slot,port,AVG(rx_dbm) AS rx FROM olt_onus WHERE olt_id=? AND status="online" AND rx_dbm IS NOT NULL GROUP BY frame,slot,port', [$olt['id']]) as $r) {
            $signals[$r['frame'] . '/' . $r['slot'] . '/' . $r['port']] = round((float) $r['rx'], 2);
        }
        $onus = [];
        foreach (Db::select('SELECT frame,slot,port,COUNT(*) AS total,SUM(status="online") AS online FROM olt_onus WHERE olt_id=? GROUP BY frame,slot,port', [$olt['id']]) as $r) {
            $onus[$r['frame'] . '/' . $r['slot'] . '/' . $r['port']] = ['total' => (int) $r['total'], 'online' => (int) $r['online']];
        }
        $rate = static fn (mixed $bps) => $bps === null ? null : Format::bytes((float) $bps / 8) . '/s';

        return [
            'lastRead' => $olt['last_hw_at'],
            'error' => (string) ($olt['last_hw_error'] ?? ''),
            'boards' => array_map(static function (array $b) {
                $state = OltMonitor::boardState($b['status_code'] === null ? null : (int) $b['status_code'], $b['online_code'] === null ? null : (int) $b['online_code']);

                return [
                    'position' => $b['frame'] . '/' . $b['slot'],
                    'name' => (string) $b['name'],
                    'status' => $state['text'],
                    'tone' => $state['ok'] ? 'success' : ($state['alert'] ? 'danger' : 'warning'),
                    'role' => match ((int) $b['work_mode']) {
                        1 => 'Active', 2 => 'Standby', default => ''
                    },
                    'power' => match ((int) $b['power_input']) {
                        1 => 'Normal', 2 => 'Fault', default => ''
                    },
                    'serial' => (string) $b['serial'],
                    'cpu' => $b['cpu_pct'] === null ? null : (int) $b['cpu_pct'],
                    'memory' => $b['mem_pct'] === null ? null : (int) $b['mem_pct'],
                    'temperature' => $b['temperature_c'] === null ? null : (int) $b['temperature_c'],
                    'watts' => ($b['power_w'] ?? null) === null ? null : (int) $b['power_w'],
                ];
            }, Db::select('SELECT * FROM olt_boards WHERE olt_id=? ORDER BY frame,slot', [$olt['id']])),
            'fans' => array_map(static fn (array $f) => ['label' => (string) $f['label'], 'state' => (string) $f['state'], 'ok' => (bool) $f['ok'], 'speed' => $f['speed_pct'] === null ? null : (int) $f['speed_pct'], 'rpm' => ($f['speed_rpm'] ?? null) === null ? null : (int) $f['speed_rpm']], Db::select('SELECT * FROM olt_fans WHERE olt_id=? ORDER BY label', [$olt['id']])),
            'ports' => array_map(static function (array $p) use ($onus, $rate, $inventory, $signals) {
                $key = preg_match('~(\\d+)\\s*/\\s*(\\d+)\\s*/\\s*(\\d+)~', (string) $p['name'], $m) ? $m[1] . '/' . $m[2] . '/' . $m[3] : '';
                $count = $onus[$key] ?? null;
                $pon = $p['kind'] === 'pon' ? ($inventory['pon'][$key] ?? null) : null;
                $uplink = $p['kind'] === 'uplink' ? ($inventory['uplinks'][$key] ?? null) : null;

                return [
                    'id' => (int) $p['if_index'],
                    'name' => (string) $p['name'],
                    'alias' => (string) $p['alias'],
                    'kind' => (string) $p['kind'],
                    'admin' => $p['admin_up'] === null ? null : (bool) $p['admin_up'],
                    'oper' => $p['oper_up'] === null ? null : (bool) $p['oper_up'],
                    'speed' => $p['speed_mbps'] === null ? null : (int) $p['speed_mbps'],
                    'in' => $rate($p['in_bps']),
                    'out' => $rate($p['out_bps']),
                    'errors' => $p['in_errors'] === null && $p['out_errors'] === null ? null : number_format((float) $p['in_errors'] + (float) $p['out_errors'], 0, '.', ''),
                    'onus' => $p['kind'] === 'pon' ? ($count ?? ['total' => 0, 'online' => 0]) : null,
                    'signal' => $signals[$key] ?? null,
                    // From the command line, read every 15 minutes: null until then, or with telnet off.
                    'pon' => $pon === null ? null : ['tx' => $pon['tx'] ?? null, 'temperature' => $pon['temperature'] ?? null, 'autofind' => $pon['autofind'] ?? null, 'min' => $pon['min_km'] ?? null, 'max' => $pon['max_km'] ?? null, 'wavelength' => $pon['wavelength'] ?? null, 'rogue' => (string) ($pon['rogue'] ?? '')],
                    'uplink' => $uplink === null ? null : ['type' => (string) ($uplink['type'] ?? ''), 'optic' => (string) ($uplink['optic'] ?? ''), 'duplex' => (string) ($uplink['duplex'] ?? ''), 'native' => $uplink['native'] ?? null, 'vlans' => array_values((array) ($uplink['vlans'] ?? []))],
                ];
            }, Db::select('SELECT * FROM olt_ports WHERE olt_id=? ORDER BY kind DESC,if_index', [$olt['id']])),
        ];
    }

    public function graph(Request $request, string $id): JsonResponse
    {
        $olt = Olts::find((int) $id);
        abort_if(!$olt, 404, 'OLT not found.');
        $range = self::range($request->query('range'));
        $chart = (string) $request->query('chart', 'onus');
        if ($chart === 'port') {
            $port = Db::row('SELECT if_index,name FROM olt_ports WHERE olt_id=? AND if_index=?', [$olt['id'], (int) $request->query('port', 0)]);
            abort_if(!$port, 404, 'Port not found.');
            $data = MonitoringData::oltHardwareChart($olt, 'port', $range, $port);
        } else {
            $data = in_array($chart, ['cpu', 'temperature', 'traffic'], true) ? MonitoringData::oltHardwareChart($olt, $chart, $range) : MonitoringData::oltChart($olt, $range);
        }

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    private static function range(mixed $requested): string
    {
        return is_string($requested) && isset(OltMonitor::RANGES[$requested]) ? $requested : '24h';
    }

    /** @return array{0:int,1:int,2:int} page, page count, offset */
    private static function page(Request $request, int $total): array
    {
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($pages, (int) $request->query('page', 1)));
        return [$page, $pages, ($page - 1) * self::PAGE_SIZE];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    public static function onuRow(array $o): array
    {
        return [
            'active' => ($o['active'] ?? null) === null ? null : (bool) $o['active'],
            'serviceProfile' => (string) ($o['service_profile'] ?? ''),
            'id' => (int) $o['id'],
            'position' => Olts::position($o),
            'sn' => (string) $o['sn'],
            'description' => (string) $o['description'],
            'model' => (string) $o['onu_model'],
            'profile' => (string) $o['line_profile'],
            'status' => (string) $o['status'],
            'downCause' => (string) $o['down_cause'],
            'lastUp' => $o['last_up_at'],
            'lastDown' => $o['last_down_at'],
            'rx' => $o['rx_dbm'] === null ? null : (float) $o['rx_dbm'],
            'oltRx' => $o['olt_rx_dbm'] === null ? null : (float) $o['olt_rx_dbm'],
            'distance' => $o['distance_m'] === null ? null : (int) $o['distance_m'],
            'macs' => $o['mac_count'] === null ? null : (int) $o['mac_count'],
            'temperature' => $o['temperature_c'] === null ? null : (int) $o['temperature_c'],
            'seen' => (string) $o['seen_at'],
            'clients' => array_values(array_filter(explode("\n", (string) ($o['clients'] ?? '')))),
        ];
    }

    public function mapping(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'unlinked');
        $evidence = OltMapper::macEvidence();
        $links = [];
        foreach (Db::select('SELECT customer_id,olt_id,sn FROM customer_onus WHERE source<>"none"') as $l) {
            $links[(int) $l['customer_id']] = $l;
        }
        $differs = [];
        $suggested = [];
        foreach ($evidence as $customerId => $e) {
            $link = $links[$customerId] ?? null;
            if ($link === null) {
                $suggested[] = $customerId;
            } elseif (!array_filter($e['onus'], static fn (array $o) => (int) $o['olt_id'] === (int) $link['olt_id'] && $o['sn'] === $link['sn'])) {
                $differs[] = $customerId;
            }
        }
        $linkedSql = 'EXISTS (SELECT 1 FROM customer_onus l WHERE l.customer_id=c.id AND l.source<>"none")';
        $in = static fn (array $ids) => $ids ? 'c.id IN (' . implode(',', array_map('intval', $ids)) . ')' : '1=0';
        $where = ' WHERE c.account_status<>"archived" AND ' . match ($filter) {
            'linked' => $linkedSql,
            'suggested' => $in($suggested),
            'differs' => $in($differs),
            'all' => '1=1',
            default => 'NOT ' . $linkedSql,
        };
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (c.username LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company_name LIKE ?)';
            $params = [$like, $like, $like, $like];
        }
        $total = (int) Db::value('SELECT COUNT(*) FROM customer_profiles c' . $where, $params);
        [$page, $pages, $offset] = self::page($request, $total);
        $rows = Db::select('SELECT c.id,c.username,c.first_name,c.last_name,c.company_name,l.sn,l.source,l.evidence,o.frame,o.slot,o.port,o.ont_id,o.status,o.rx_dbm,t.name AS olt_name
            FROM customer_profiles c LEFT JOIN customer_onus l ON l.customer_id=c.id AND l.source<>"none" LEFT JOIN olts t ON t.id=l.olt_id LEFT JOIN olt_onus o ON o.olt_id=l.olt_id AND o.sn=l.sn'
            . $where . ' ORDER BY c.username LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset, $params);
        $customers = (int) Db::value('SELECT COUNT(*) FROM customer_profiles WHERE account_status<>"archived"');
        return Inertia::render('olts/Mapping', [
            'filters' => ['q' => $q, 'filter' => $filter],
            'counts' => ['all' => $customers, 'linked' => count($links), 'unlinked' => max(0, $customers - count($links)), 'suggested' => count($suggested), 'differs' => count($differs)],
            'canLink' => Auth::can('clients.manage'),
            'result' => ['total' => $total, 'page' => $page, 'pages' => $pages, 'size' => self::PAGE_SIZE, 'rows' => array_map(static function (array $r) use ($evidence, $differs) {
                $found = $evidence[(int) $r['id']] ?? null;
                return [
                    'username' => (string) $r['username'],
                    'customer' => trim($r['first_name'] . ' ' . $r['last_name']) ?: (string) $r['company_name'],
                    'link' => $r['sn'] === null ? null : [
                        'sn' => (string) $r['sn'],
                        'olt' => (string) $r['olt_name'],
                        'position' => $r['frame'] === null ? null : Olts::position($r),
                        'status' => (string) ($r['status'] ?? 'missing'),
                        'rx' => $r['rx_dbm'] === null ? null : (float) $r['rx_dbm'],
                        'source' => OltMapper::SOURCES[$r['source']] ?? (string) $r['source'],
                    ],
                    // Shown only when it adds something: no link yet, or a link the evidence disagrees with.
                    'found' => $found === null || ($r['sn'] !== null && !in_array((int) $r['id'], $differs, true)) ? null : [
                        'mac' => OltMapper::formatMac($found['mac']),
                        'onus' => array_map(static fn (array $o) => ['sn' => (string) $o['sn'], 'position' => Olts::position($o)], $found['onus']),
                    ],
                ];
            }, $rows)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function customer(string $username): array
    {
        $row = Db::row('SELECT * FROM customer_profiles WHERE username=?', [$username]);
        abort_if(!$row, 404, 'Client not found.');
        return $row;
    }

    public function link(Request $request, string $username): RedirectResponse
    {
        Auth::requireCan('clients.manage');
        $row = self::customer($username);
        if (Clients::isArchived($row)) {
            return $this->fail('This client is archived.');
        }
        try {
            $onu = OltMapper::linkBySerial((int) $row['id'], (string) $row['username'], (string) $request->input('sn', ''), (string) (Auth::user()['username'] ?? ''));
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        return back()->with('flash', ['type' => 'success', 'message' => $row['username'] . ' is linked to ONU ' . $onu['sn'] . ' (' . Olts::position($onu) . ').']);
    }

    public function unlink(string $username): RedirectResponse
    {
        Auth::requireCan('clients.manage');
        $row = self::customer($username);
        OltMapper::unlink((int) $row['id'], (string) $row['username']);
        return back()->with('flash', ['type' => 'success', 'message' => $row['username'] . ' is no longer linked to an ONU. It will not be linked again automatically.']);
    }

    /**
     * For the ONU tab on a client's page.
     *
     * @return array<string, mixed>
     */
    public static function clientTab(int $customerId): array
    {
        $linked = OltMapper::forCustomer($customerId);
        $found = OltMapper::macEvidence()[$customerId] ?? null;
        return [
            'link' => $linked === null ? null : [
                'sn' => (string) $linked['link']['sn'],
                'olt' => (string) $linked['link']['olt_name'],
                'oltId' => (int) $linked['link']['olt_id'],
                'source' => OltMapper::SOURCES[$linked['link']['source']] ?? (string) $linked['link']['source'],
                'evidence' => (string) $linked['link']['evidence'],
                'linkedAt' => (string) $linked['link']['linked_at'],
            ],
            'onu' => $linked !== null && $linked['onu'] !== null ? self::onuRow($linked['onu'] + ['clients' => null]) : null,
            'found' => $found === null ? null : ['mac' => OltMapper::formatMac($found['mac']), 'onus' => array_map(static fn (array $o) => ['sn' => (string) $o['sn'], 'position' => Olts::position($o)], $found['onus'])],
            'hasOlts' => (bool) Db::value('SELECT COUNT(*) FROM olts'),
            // Where a new ONU can be added for this client.
            'addOn' => array_map(static fn (array $o) => ['id' => (int) $o['id'], 'name' => (string) $o['name']], Db::select('SELECT id,name FROM olts WHERE enabled=1 AND telnet_enabled=1 AND manage_enabled=1 ORDER BY name')),
        ];
    }
}
