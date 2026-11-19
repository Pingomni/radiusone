<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Auth;
use App\Support\ClientQuery;
use App\Support\Db;
use App\Support\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The top bar's search (Ctrl K): clients by name, username, phone, IP or MAC, and invoices by number. */
final class SearchController extends Controller
{
    private const LIMIT = 6;

    public function search(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 64);
        $clients = [];
        $invoices = [];
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
        }

        return response()->json([
            'q' => $q,
            'clients' => $clients,
            'clientTotal' => $clientTotal ?? 0,
            'invoices' => $invoices,
        ])->header('Cache-Control', 'no-store');
    }
}
