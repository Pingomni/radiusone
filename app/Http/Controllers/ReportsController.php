<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Format;
use App\Http\Support\Present;
use App\Support\Accounting;
use App\Support\ClientQuery;
use App\Support\Db;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reports (accounting totals) and Logs (audit log, authentication attempts). */
final class ReportsController extends Controller
{
    public function reports(Request $request): Response
    {
        // A bounded date range keeps the daily totals off a full accounting-table scan.
        $validDate = static fn ($v) => is_string($v) && ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v;
        $to = $validDate($request->query('to')) ? (string) $request->query('to') : date('Y-m-d');
        $from = $validDate($request->query('from')) ? (string) $request->query('from') : date('Y-m-d', strtotime($to . ' -30 days'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $user = trim((string) $request->query('username', ''));
        $range = 'acctstarttime >= ? AND acctstarttime < DATE_ADD(?, INTERVAL 1 DAY)' . ($user !== '' ? ' AND username = ?' : '');
        $params = $user !== '' ? [$from, $to, $user] : [$from, $to];

        $online = Db::select('SELECT * FROM radacct WHERE acctstoptime IS NULL ORDER BY acctstarttime DESC,radacctid DESC LIMIT 200');
        $sessions = Db::select("SELECT * FROM radacct WHERE $range ORDER BY acctstarttime DESC,radacctid DESC LIMIT 200", $params);
        $matches = (int) Db::value("SELECT COUNT(*) FROM radacct WHERE $range", $params);
        $daily = Db::select("SELECT DATE(acctstarttime) AS day, SUM(acctoutputoctets) AS down, SUM(acctinputoctets) AS up FROM radacct WHERE $range GROUP BY DATE(acctstarttime) ORDER BY day DESC", $params);
        $today = (int) Db::value('SELECT COALESCE(SUM(COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= CURDATE() AND acctstarttime < DATE_ADD(CURDATE(), INTERVAL 1 DAY)');
        $month = (int) Db::value('SELECT COALESCE(SUM(COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0)),0) FROM radacct WHERE acctstarttime >= DATE_FORMAT(CURDATE(), "%Y-%m-01") AND acctstarttime < DATE_ADD(DATE_FORMAT(CURDATE(), "%Y-%m-01"),INTERVAL 1 MONTH)');
        $quality = Accounting::quality();

        return Inertia::render('reports/Reports', [
            'filters' => ['from' => $from, 'to' => $to, 'username' => $user, 'max' => date('Y-m-d')],
            'metrics' => [
                Present::metric('Users online', (int) Db::value('SELECT COUNT(DISTINCT username) FROM radacct r WHERE ' . ClientQuery::fresh('r')), 'signal'),
                Present::metric('Sessions started today', (int) Db::value('SELECT COUNT(*) FROM radacct WHERE acctstarttime>=CURDATE() AND acctstarttime<DATE_ADD(CURDATE(),INTERVAL 1 DAY)'), 'reports'),
                Present::metric('Session totals: started today', Format::bytes($today), 'signal'),
                Present::metric('Session totals: started this month', Format::bytes($month), 'signal'),
            ],
            'quality' => ['stale' => (int) $quality['stale_or_unknown'], 'anomalous' => (int) $quality['anomalous']],
            'online' => array_map(static fn (array $r) => [
                'id' => (int) $r['radacctid'],
                'username' => (string) $r['username'],
                'ip' => (string) $r['framedipaddress'],
                'nas' => (string) $r['nasipaddress'],
                'started' => (string) $r['acctstarttime'],
                'updated' => (string) ($r['acctupdatetime'] ?? ''),
                'state' => Accounting::state($r),
            ], $online),
            'daily' => array_map(static fn (array $r) => [
                'day' => (string) $r['day'],
                'down' => Format::bytes((int) $r['down']),
                'up' => Format::bytes((int) $r['up']),
                'total' => Format::bytes((int) $r['down'] + (int) $r['up']),
            ], $daily),
            'sessions' => array_map(static fn (array $r) => [
                'id' => (int) $r['radacctid'],
                'username' => (string) $r['username'],
                'start' => (string) $r['acctstarttime'],
                'stop' => (string) ($r['acctstoptime'] ?? ''),
                'time' => Format::seconds((int) $r['acctsessiontime']),
                'down' => Format::bytes(Format::acctDownload($r)),
                'up' => Format::bytes(Format::acctUpload($r)),
                'quality' => implode('; ', Accounting::anomalies($r)) ?: 'snapshot only',
            ], $sessions),
            'sessionMatches' => $matches,
        ]);
    }

    public function logs(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $reply = in_array($request->query('reply'), ['reject', 'accept'], true) ? (string) $request->query('reply') : '';
        $authUser = trim((string) $request->query('auth_user', ''));
        [$auditWhere, $auditParams] = $q === ''
            ? ['1=1', []]
            : ['(actor_username LIKE ? OR action LIKE ? OR entity_type LIKE ? OR entity_id LIKE ? OR ip_address LIKE ?)', array_fill(0, 5, '%' . $q . '%')];
        $audit = Db::select("SELECT * FROM audit_logs WHERE $auditWhere ORDER BY created_at DESC, id DESC LIMIT 300", $auditParams);
        $auditMatches = (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE $auditWhere", $auditParams);
        $hasPostAuth = (bool) Db::value("SHOW TABLES LIKE 'radpostauth'");
        $rejectsToday = $hasPostAuth ? (int) Db::value('SELECT COUNT(*) FROM radpostauth WHERE DATE(authdate)=CURDATE() AND reply LIKE "%Reject%"') : 0;

        return Inertia::render('reports/Logs', [
            'filters' => ['q' => $q, 'reply' => $reply, 'auth_user' => $authUser],
            'metrics' => [
                Present::metric('Audit events today', (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at)=CURDATE()'), 'logs'),
                Present::metric('Audit events total', (int) Db::value('SELECT COUNT(*) FROM audit_logs'), 'logs'),
                Present::metric('Auth attempts today', $hasPostAuth ? (int) Db::value('SELECT COUNT(*) FROM radpostauth WHERE DATE(authdate)=CURDATE()') : 'n/a', 'shield'),
                Present::metric('Rejects today', $hasPostAuth ? $rejectsToday : 'n/a', 'lock', $rejectsToday > 0 ? 'warning' : '', $hasPostAuth ? '/logs?reply=reject' : ''),
            ],
            'audit' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'],
                'time' => (string) $r['created_at'],
                'actor' => (string) $r['actor_username'],
                'action' => (string) $r['action'],
                'entityType' => (string) $r['entity_type'],
                'entityId' => (string) $r['entity_id'],
                'ip' => (string) $r['ip_address'],
            ], $audit),
            'auditMatches' => $auditMatches,
            'attempts' => $hasPostAuth ? array_map(static fn (array $r) => [
                'time' => (string) $r['authdate'],
                'username' => (string) $r['username'],
                'reply' => (string) $r['reply'],
            ], self::postAuthAttempts($reply, $authUser)) : null,
        ]);
    }

    private static function postAuthAttempts(string $reply, string $username): array
    {
        $where = ['1=1'];
        $params = [];
        if ($reply !== '') {
            $where[] = 'reply LIKE ?';
            $params[] = '%' . ($reply === 'reject' ? 'Reject' : 'Accept') . '%';
        }
        if ($username !== '') {
            $where[] = 'username LIKE ?';
            $params[] = '%' . $username . '%';
        }
        return Db::select('SELECT username, reply, authdate FROM radpostauth WHERE ' . implode(' AND ', $where) . ' ORDER BY authdate DESC LIMIT 200', $params);
    }
}
