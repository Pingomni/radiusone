<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Clients;
use App\Support\Auth;
use App\Support\Db;
use App\Support\Olt\HuaweiCli;
use App\Support\Olt\OltManager;
use App\Support\Olt\OltMapper;
use App\Support\Olt\Olts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(string $id, string $onu): Response
    {
        [$olt, $row] = self::find($id, $onu);
        $clients = Db::select('SELECT c.username,c.first_name,c.last_name,c.company_name FROM customer_onus l JOIN customer_profiles c ON c.id=l.customer_id WHERE l.olt_id=? AND l.sn=? AND l.source<>"none" ORDER BY c.username', [$olt['id'], $row['sn']]);
        return Inertia::render('olts/Onu', [
            'olt' => ['id' => (int) $olt['id'], 'name' => (string) $olt['name'], 'canRead' => OltManager::canRead($olt), 'canChange' => OltManager::canChange($olt)],
            'onu' => OltController::onuRow($row + ['clients' => implode("\n", array_column($clients, 'username'))]),
            'clients' => array_map(static fn (array $c) => ['username' => (string) $c['username'], 'name' => trim($c['first_name'] . ' ' . $c['last_name']) ?: (string) $c['company_name']], $clients),
        ]);
    }

    /** Asks the OLT now. Only reads. */
    public function live(string $id, string $onu): JsonResponse
    {
        [$olt, $row] = self::find($id, $onu);
        set_time_limit(90);
        try {
            $payload = ['ok' => true, 'data' => OltManager::live($olt, $row)];
        } catch (RuntimeException $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    public function change(Request $request, string $id, string $onu, string $action): RedirectResponse
    {
        [$olt, $row] = self::find($id, $onu);
        if ($action === 'delete') {
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
