<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Auth;
use App\Support\ClientQuery;
use App\Support\Db;
use App\Support\License;
use App\Support\Olt\Olts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The top bar's search (Ctrl K): clients by name, username, phone, IP or MAC; invoices by number; and the network:
 * ONUs by name or serial on every OLT, OLTs and routers by name or address.
 */
final class SearchController extends Controller
{
    private const LIMIT = 6;

    public function search(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 64);
        $clients = [];
        $invoices = [];
        $onus = [];
        $devices = [];
        if (mb_strlen($q) >= 2) {
            if (Auth::can('clients.view')) {
                $page = ClientQuery::page(['q' => $q], 1, self::LIMIT);
                foreach ($page['rows'] as $row) {
                    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')) ?: (string) ($row['company_name'] ?? '');
                    $clients[] = [
                        'username' => (string) $row['username'],
                        'name' => $name,
                        'ip' => (string) ($row['online_ip'] ?: ($row['framed_ip_address'] ?? '')),
                        'online' => (int) ($row['online_count'] ?? 0) > 0,
                        'href' => '/clients/' . rawurlencode((string) $row['username']) . '/edit',
                    ];
                }
                $clientTotal = (int) $page['total'];
            }
            if (Auth::can('billing.view') && License::allows('billing')) {
                foreach (Db::select('SELECT id, invoice_number, username FROM billing_invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT ' . self::LIMIT, ['%' . $q . '%']) as $row) {
                    $invoices[] = ['number' => (string) $row['invoice_number'], 'username' => (string) $row['username'], 'href' => '/billing/invoices/' . (int) $row['id']];
                }
            }
            if (Auth::can('nas.view')) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                try {
                    foreach (Db::select('SELECT o.id, o.olt_id, o.sn, o.description, o.status, o.frame, o.slot, o.port, o.ont_id, t.name AS olt FROM olt_onus o JOIN olts t ON t.id=o.olt_id
                        WHERE o.sn LIKE ? OR o.description LIKE ? ORDER BY o.description LIMIT ' . self::LIMIT, [$like, $like]) as $row) {
                        $onus[] = ['name' => (Olts::onuName((string) $row['description']) ?: (string) $row['sn']), 'sn' => (string) $row['sn'], 'where' => $row['olt'] . ' ' . Olts::position($row), 'online' => $row['status'] === 'online',
                            'href' => '/olts/' . (int) $row['olt_id'] . '/onus/' . (int) $row['id']];
                    }
                    foreach (Db::select('SELECT id, name, host FROM olts WHERE name LIKE ? OR host LIKE ? ORDER BY name LIMIT 3', [$like, $like]) as $row) {
                        $devices[] = ['name' => (string) $row['name'], 'sub' => 'OLT ' . $row['host'], 'href' => '/olts/' . (int) $row['id']];
                    }
                } catch (Throwable) {
                    // No OLT tables on this install: the search still finds clients and routers.
                }
                foreach (Db::select('SELECT id, shortname, nasname FROM nas WHERE shortname LIKE ? OR nasname LIKE ? ORDER BY shortname LIMIT 3', [$like, $like]) as $row) {
                    $devices[] = ['name' => (string) $row['shortname'], 'sub' => 'Router ' . $row['nasname'], 'href' => '/nas/' . (int) $row['id'] . '/edit'];
                }
            }
        }

        return response()->json([
            'q' => $q,
            'clients' => $clients,
            'clientTotal' => $clientTotal ?? 0,
            'invoices' => $invoices,
            'onus' => $onus,
            'devices' => $devices,
        ])->header('Cache-Control', 'no-store');
    }
}
