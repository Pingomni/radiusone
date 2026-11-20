<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\MonitoringData;
use App\Http\Support\Nas;
use App\Support\Audit;
use App\Support\Auth;
use App\Support\Db;
use App\Support\MicroCache;
use App\Support\NasMonitor;
use App\Support\NasTables;
use App\Support\NasTraffic;
use App\Support\Olt\OltMonitor;
use App\Support\ServerMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Monitoring overview, RADIUS server, live NAS monitor and history graphs (JSON for the live panels). */
final class MonitoringController extends Controller
{
    public function index(): Response
    {
        $lastPoll = NasTraffic::available() ? (int) Db::value('SELECT MAX(sampled_at) FROM nas_snmp_samples WHERE nas_id=?', [ServerMonitor::ID]) : 0;
        return Inertia::render('monitoring/Index', [
            'overview' => MonitoringData::overview(),
            'lastPoll' => $lastPoll === 0 ? null : ['age' => MonitoringData::age(time() - $lastPoll), 'stale' => time() - $lastPoll > NasTraffic::STALE_SECONDS],
        ]);
    }

    /**
     * Routers and OLTs in one list with their state: where something is wrong shows without opening each device.
     */
    public function devices(Request $request): Response
    {
        $overview = MonitoringData::overview();
        $value = static function (array $items, string $label): string {
            foreach ($items as $item) {
                if ($item['label'] === $label) {
                    return (string) $item['value'];
                }
            }
            return '-';
        };
        $readings = static fn (array $list) => implode(' · ', array_map(static fn (array $r) => $r['label'] . ' ' . $r['value'], array_slice($list, 0, 3)));
        // What is wrong with each device, from the same checks as the Monitoring page.
        $problems = static function (string $href) use ($overview): array {
            $texts = [];
            foreach ($overview['problems'] as $problem) {
                $link = (string) $problem['href'];
                if ($link === $href || str_starts_with($link, $href . '/') || str_starts_with($link, $href . '?')) {
                    $texts[] = (string) preg_replace('/^[^:]{1,80}: /', '', (string) $problem['text']);
                }
            }
            return $texts;
        };
        $rows = [];
        foreach ($overview['nas'] as $n) {
            $href = '/nas/' . $n['id'];
            $rows[] = ['key' => 'nas-' . $n['id'], 'kind' => 'router', 'name' => $n['name'], 'address' => $n['ip'], 'model' => $n['vendor'], 'href' => $href . '/edit', 'monitor' => $href . '/monitor',
                'tone' => $n['radiusDisabled'] ? 'neutral' : ($n['danger'] ? 'danger' : 'good'), 'state' => $n['radiusDisabled'] ? 'RADIUS off' : ($n['danger'] ? 'Problem' : 'Working'),
                'count' => $value($n['items'], 'Online now') . ' online', 'countHref' => '/sessions?nas=' . $n['id'],
                'detail' => $readings($n['readings']) ?: 'Last accounting ' . $value($n['items'], 'Last accounting'), 'problems' => $problems($href)];
        }
        foreach ($overview['olts'] as $o) {
            $href = '/olts/' . $o['id'];
            $rows[] = ['key' => 'olt-' . $o['id'], 'kind' => 'olt', 'name' => $o['name'], 'address' => $o['host'], 'model' => $o['model'], 'href' => $href, 'monitor' => $href . '?tab=graphs',
                'tone' => !$o['enabled'] ? 'neutral' : ($o['danger'] ? 'danger' : 'good'), 'state' => !$o['enabled'] ? 'Not read' : ($o['danger'] ? 'Problem' : 'Working'),
                'count' => $value($o['items'], 'ONUs online') . ' ONUs', 'countHref' => '/onus?olt=' . $o['id'],
                'detail' => $readings($o['readings']), 'problems' => $problems($href)];
        }
        $view = (string) $request->query('view', '');
        $view = in_array($view, ['router', 'olt', 'problems'], true) ? $view : '';
        $bad = array_values(array_filter($rows, static fn (array $r) => $r['tone'] === 'danger' || (bool) $r['problems']));
        return Inertia::render('monitoring/Devices', [
            'counts' => ['all' => count($rows), 'router' => count($overview['nas']), 'olt' => count($overview['olts']), 'problems' => count($bad)],
            'view' => $view,
            'devices' => $view === 'problems' ? $bad : array_values(array_filter($rows, static fn (array $r) => $view === '' || $r['kind'] === $view)),
            'canAdd' => Auth::can('nas.manage'),
        ]);
    }

    public function live(): JsonResponse
    {
        return self::json(MonitoringData::overview());
    }

    /** "Poll now": runs the same recording as the scheduled worker, for everything or one device. */
    public function poll(Request $request): RedirectResponse
    {
        $target = (string) $request->input('target', 'all');
        $only = $target === 'all' ? null : (ctype_digit($target) ? (int) $target : null);
        $return = (string) $request->input('return', '/monitoring');
        if (!preg_match('#^/(monitoring(/server)?|nas/\d+/monitor)$#D', $return)) {
            $return = '/monitoring';
        }
        if (!NasTraffic::available()) {
            return $this->fail('Apply database migration 022_nas_snmp_samples.sql before polling.', $return);
        }
        if ($only === null) {
            OltMonitor::requestAll();
        }
        @set_time_limit(90);
        try {
            $summary = NasTraffic::recordAll(null, $only);
        } catch (RuntimeException $e) {
            return $this->warn($return, str_contains($e->getMessage(), 'still running') ? 'A poll is already running (the scheduled poller). Try again in a few seconds.' : $e->getMessage());
        }
        $message = MonitoringData::pollMessage($summary);
        return $summary['failed'] ? $this->warn($return, $message) : $this->ok($return, $message);
    }

    /** Detail page for the RADIUS server: live status plus the same history graphs as a NAS. */
    public function server(Request $request): Response
    {
        return Inertia::render('monitoring/Server', [
            'host' => (string) gethostname(),
            'status' => MonitoringData::serverStatus(),
            'history' => MonitoringData::history(ServerMonitor::pseudoNas(), $request->query('range'), $request->query('if')),
            'serverId' => ServerMonitor::ID,
            'restartable' => Auth::can('settings.manage') ? ServerMonitor::restartable() : [],
        ]);
    }

    /** Restart button: hands one service to the root helper (config check first, restart two seconds later). */
    public function restartService(Request $request): RedirectResponse
    {
        $unit = (string) $request->input('unit');
        $label = ServerMonitor::restartable()[$unit] ?? $unit;
        try {
            ServerMonitor::restart($unit);
        } catch (RuntimeException $e) {
            Audit::log('service_restart_failed', 'server_service', $unit, null, ['error' => mb_substr($e->getMessage(), 0, 300)]);
            return $this->fail($e->getMessage(), '/monitoring/server');
        }
        Audit::log('service_restart', 'server_service', $unit);
        return $this->ok('/monitoring/server', "$label is restarting. Its status updates here within about 15 seconds.");
    }

    public function serverLive(): JsonResponse
    {
        return self::json(MonitoringData::serverStatus());
    }

    public function nas(Request $request, string $id): Response|RedirectResponse
    {
        if ((int) $id === ServerMonitor::ID) {
            return redirect('/monitoring/server');
        }
        $nas = Nas::monitorRow($id);
        $vendor = strtolower((string) (($nas['vendor'] ?? '') ?: ($nas['type'] ?? '')));
        $snmp = NasMonitor::configured(NasMonitor::settings((int) $nas['id']));
        return Inertia::render('monitoring/Nas', [
            'nas' => ['id' => (int) $nas['id'], 'name' => (string) ($nas['shortname'] ?: $nas['nasname']), 'ip' => (string) $nas['nasname']],
            'snapshot' => MonitoringData::nasSnapshot($nas),
            'history' => MonitoringData::history($nas, $request->query('range'), $request->query('if')),
            'vendorLabel' => isset(Nas::VENDORS[$vendor]) ? Nas::vendorLabel($vendor) : 'this device',
            'oids' => array_map(static fn (array $r) => ['group' => (string) $r[0], 'metric' => (string) $r[1], 'oid' => (string) $r[2], 'notes' => (string) $r[3]], NasMonitor::oidReference($vendor)),
            'canManage' => Auth::can('nas.manage'),
            'tables' => $snmp ? NasTables::TABLES : null,
            // Without SNMP: what is known anyway (ping, accounting, disconnect method), as on the overview card.
            'basics' => $snmp ? null : (array_values(array_filter(MonitoringData::overview()['nas'], static fn (array $c) => $c['id'] === (int) $nas['id']))[0]['items'] ?? []),
        ]);
    }

    public function nasLive(string $id): JsonResponse
    {
        return self::json(MonitoringData::nasSnapshot(Nas::monitorRow($id)));
    }

    /** One routing / layer-2 table, read live over SNMP when the operator opens its tab. */
    public function nasTable(string $id, string $table): JsonResponse
    {
        $nas = Nas::monitorRow($id);
        try {
            // Opening tabs back and forth does not poll the router again within 10 seconds.
            return self::json(MicroCache::remember('nas-table:' . (int) $nas['id'] . ':' . $table, 10, static fn () => NasTables::read($nas, $table)));
        } catch (RuntimeException $e) {
            return self::json(['error' => $e->getMessage()]);
        }
    }

    public function trafficGraph(Request $request, string $id): JsonResponse
    {
        $nas = Nas::monitorRow($id);
        if (!NasTraffic::available()) {
            return self::json(['message' => 'Apply database migration 022_nas_snmp_samples.sql to record traffic history.']);
        }
        [$selected, $range, $interfaces] = MonitoringData::selection((int) $nas['id'], $request->query('range'), $request->query('if'));
        return self::json(MonitoringData::trafficChart($nas, $selected, $range, $interfaces));
    }

    public function healthGraph(Request $request, string $id): JsonResponse
    {
        $nas = Nas::monitorRow($id);
        if (!NasTraffic::healthAvailable()) {
            return self::json(['message' => 'Apply database migration 023_nas_snmp_health_samples.sql to record CPU and memory history.']);
        }
        return self::json(MonitoringData::healthChart($nas, MonitoringData::range($request->query('range'))));
    }

    public function temperatureGraph(Request $request, string $id): JsonResponse
    {
        $nas = Nas::monitorRow($id);
        if (!NasTraffic::temperatureAvailable()) {
            return self::json(['message' => 'Apply database migration 024_nas_snmp_temperature_samples.sql to record temperature history.']);
        }
        return self::json(MonitoringData::temperatureChart($nas, MonitoringData::range($request->query('range'))));
    }

    private static function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store');
    }
}
