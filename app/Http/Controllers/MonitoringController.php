<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\MonitoringData;
use App\Http\Support\Nas;
use App\Support\Auth;
use App\Support\Db;
use App\Support\NasMonitor;
use App\Support\NasTraffic;
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
        ]);
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
        return Inertia::render('monitoring/Nas', [
            'nas' => ['id' => (int) $nas['id'], 'name' => (string) ($nas['shortname'] ?: $nas['nasname']), 'ip' => (string) $nas['nasname']],
            'snapshot' => MonitoringData::nasSnapshot($nas),
            'history' => MonitoringData::history($nas, $request->query('range'), $request->query('if')),
            'vendorLabel' => Nas::VENDORS[$vendor]['label'] ?? 'this device',
            'oids' => array_map(static fn (array $r) => ['group' => (string) $r[0], 'metric' => (string) $r[1], 'oid' => (string) $r[2], 'notes' => (string) $r[3]], NasMonitor::oidReference($vendor)),
            'canManage' => Auth::can('nas.manage'),
        ]);
    }

    public function nasLive(string $id): JsonResponse
    {
        return self::json(MonitoringData::nasSnapshot(Nas::monitorRow($id)));
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
