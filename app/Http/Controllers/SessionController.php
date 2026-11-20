<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Format;
use App\Support\Accounting;
use App\Support\Auth;
use App\Support\Db;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every open session on every router in one list: who is connected where, with what address, and for how long. The
 * place to find or disconnect a session without first opening its client or its router.
 */
final class SessionController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(Request $request): Response
    {
        $routers = Db::select('SELECT n.id, n.shortname, n.nasname FROM nas n ORDER BY n.shortname');
        $q = trim((string) $request->query('q', ''));
        $view = (string) $request->query('view', '');
        $view = in_array($view, ['stale', 'all'], true) ? $view : '';
        $nasId = (int) $request->query('nas', 0);
        $nas = null;
        foreach ($routers as $r) {
            if ((int) $r['id'] === $nasId) {
                $nas = $r;
            }
        }

        // The router filter narrows the counts too, so the tabs add up to what the list shows.
        $scope = ' WHERE r.acctstoptime IS NULL';
        $scopeParams = [];
        if ($nas !== null) {
            $scope .= ' AND (r.nasipaddress=? OR r.nasipaddress=?)';
            array_push($scopeParams, $nas['nasname'], $nas['shortname']);
        }
        $fresh = Accounting::freshSql('r');
        $counts = Db::row('SELECT COUNT(*) AS open, COALESCE(SUM(' . $fresh . '),0) AS online FROM radacct r' . $scope, $scopeParams) ?? ['open' => 0, 'online' => 0];

        $where = $scope . ($view === '' ? ' AND ' . $fresh : ($view === 'stale' ? ' AND NOT (' . $fresh . ')' : ''));
        $params = $scopeParams;
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (r.username LIKE ? OR r.framedipaddress LIKE ? OR r.callingstationid LIKE ? OR EXISTS (SELECT 1 FROM customer_profiles c WHERE c.username=r.username AND (CONCAT(c.first_name, " ", c.last_name) LIKE ? OR c.company_name LIKE ?)))';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $total = (int) Db::value('SELECT COUNT(*) FROM radacct r' . $where, $params);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($pages, (int) $request->query('page', 1)));
        $rows = Db::select('SELECT r.radacctid, r.username, r.nasipaddress, r.framedipaddress, r.callingstationid, r.acctstarttime, r.acctupdatetime, r.acctstoptime,
                r.acctsessiontime, r.acctinputoctets, r.acctoutputoctets,
                (SELECT n.id FROM nas n WHERE n.nasname=r.nasipaddress OR n.shortname=r.nasipaddress LIMIT 1) AS nas_id,
                (SELECT n.shortname FROM nas n WHERE n.nasname=r.nasipaddress OR n.shortname=r.nasipaddress LIMIT 1) AS nas_name,
                c.first_name, c.last_name, c.company_name, s.name AS service
            FROM radacct r LEFT JOIN customer_profiles c ON c.username=r.username LEFT JOIN service_metadata s ON s.group_name=c.service_group'
            . $where . ' ORDER BY r.acctstarttime DESC, r.radacctid DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $params);
        $mayDisconnect = Auth::can('clients.disconnect');

        return Inertia::render('clients/Sessions', [
            'routers' => array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => (string) ($r['shortname'] ?: $r['nasname'])], $routers),
            'counts' => ['online' => (int) $counts['online'], 'stale' => (int) $counts['open'] - (int) $counts['online'], 'all' => (int) $counts['open']],
            'filters' => ['q' => $q, 'view' => $view, 'nas' => $nas === null ? 0 : $nasId],
            'result' => ['rows' => array_map(static function (array $r) use ($mayDisconnect): array {
                $online = Accounting::state($r) === 'fresh accounting';
                return [
                    'id' => (int) $r['radacctid'],
                    'username' => (string) $r['username'],
                    'name' => trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')) ?: (string) ($r['company_name'] ?? ''),
                    'known' => $r['first_name'] !== null || $r['company_name'] !== null,
                    'service' => (string) ($r['service'] ?? ''),
                    'online' => $online,
                    'nasId' => $r['nas_id'] === null ? null : (int) $r['nas_id'],
                    'router' => (string) ($r['nas_name'] ?: $r['nasipaddress']),
                    'ip' => (string) ($r['framedipaddress'] ?? ''),
                    'mac' => (string) ($r['callingstationid'] ?? ''),
                    'started' => (string) ($r['acctstarttime'] ?? ''),
                    'updated' => (string) ($r['acctupdatetime'] ?? ''),
                    'time' => Format::pppoeSessionTime($r),
                    'down' => Format::bytes(Format::acctDownload($r)),
                    'up' => Format::bytes(Format::acctUpload($r)),
                    // Only a session the router still reports can be disconnected.
                    'canDisconnect' => $mayDisconnect && $online && $r['nas_id'] !== null,
                ];
            }, $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'size' => self::PAGE_SIZE],
        ]);
    }
}
