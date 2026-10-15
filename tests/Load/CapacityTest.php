<?php

declare(strict_types=1);

namespace Tests\Load;

use App\Support\Db;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Capacity check, not part of the normal suite: about one year of data for N clients in the private
 * test database (production radacct/radpostauth definitions), then timed requests through the HTTP kernel.
 * Run: tests/run.sh tests/Load   (LOAD_CLIENTS=5000, LOAD_DAYS=365 by default)
 */
final class CapacityTest extends TestCase
{
    private const RADACCT = <<<'SQL'
        CREATE TABLE radacct (
          radacctid bigint(21) NOT NULL AUTO_INCREMENT, acctsessionid varchar(64) NOT NULL DEFAULT '', acctuniqueid varchar(32) NOT NULL DEFAULT '',
          username varchar(64) NOT NULL DEFAULT '', realm varchar(64) DEFAULT '', nasipaddress varchar(15) NOT NULL DEFAULT '', nasportid varchar(32) DEFAULT NULL,
          nasporttype varchar(32) DEFAULT NULL, acctstarttime datetime DEFAULT NULL, acctupdatetime datetime DEFAULT NULL, acctstoptime datetime DEFAULT NULL,
          acctinterval int(12) DEFAULT NULL, acctsessiontime int(12) unsigned DEFAULT NULL, acctauthentic varchar(32) DEFAULT NULL,
          connectinfo_start varchar(128) DEFAULT NULL, connectinfo_stop varchar(128) DEFAULT NULL, acctinputoctets bigint(20) DEFAULT NULL,
          acctoutputoctets bigint(20) DEFAULT NULL, calledstationid varchar(50) NOT NULL DEFAULT '', callingstationid varchar(50) NOT NULL DEFAULT '',
          acctterminatecause varchar(32) NOT NULL DEFAULT '', servicetype varchar(32) DEFAULT NULL, framedprotocol varchar(32) DEFAULT NULL,
          framedipaddress varchar(15) NOT NULL DEFAULT '', framedipv6address varchar(45) NOT NULL DEFAULT '', framedipv6prefix varchar(45) NOT NULL DEFAULT '',
          framedinterfaceid varchar(44) NOT NULL DEFAULT '', delegatedipv6prefix varchar(45) NOT NULL DEFAULT '', class varchar(64) DEFAULT NULL,
          PRIMARY KEY (radacctid), UNIQUE KEY acctuniqueid (acctuniqueid), KEY username (username), KEY framedipaddress (framedipaddress),
          KEY framedipv6address (framedipv6address), KEY framedipv6prefix (framedipv6prefix), KEY framedinterfaceid (framedinterfaceid),
          KEY delegatedipv6prefix (delegatedipv6prefix), KEY acctsessionid (acctsessionid), KEY acctsessiontime (acctsessiontime),
          KEY acctstarttime (acctstarttime), KEY acctinterval (acctinterval), KEY acctstoptime (acctstoptime), KEY nasipaddress (nasipaddress), KEY class (class)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

    private const RADPOSTAUTH = <<<'SQL'
        CREATE TABLE radpostauth (
          id int(11) NOT NULL AUTO_INCREMENT, username varchar(64) NOT NULL DEFAULT '', pass varchar(64) NOT NULL DEFAULT '',
          reply varchar(32) NOT NULL DEFAULT '', authdate timestamp(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6), class varchar(64) DEFAULT NULL,
          PRIMARY KEY (id), KEY username (username), KEY class (class), KEY idx_radpostauth_authdate (authdate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

    private function report(string $line): void
    {
        fwrite(STDERR, $line . PHP_EOL);
    }

    private function timed(string $label, callable $work): float
    {
        $start = hrtime(true);
        $work();
        $ms = (hrtime(true) - $start) / 1e6;
        $this->report(sprintf('  %-46s %8.0f ms', $label, $ms));
        return $ms;
    }

    private function seedYear(int $clients, int $days): void
    {
        $pdo = Db::pdo();
        $sessions = $clients * $days;            // one reconnect per client per day
        $auths = (int) round($sessions * 1.05);  // plus ~5% rejects
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DROP TABLE radacct');
        $pdo->exec(self::RADACCT);
        // Indexes this app adds to the FreeRADIUS table (production gets them from frweb:migrate).
        $pdo->exec((string) file_get_contents(base_path('database/sql/027_radacct_nas_stop_index.sql')));
        $pdo->exec('DROP TABLE radpostauth');
        $pdo->exec(self::RADPOSTAUTH);

        $this->timed('seed plans, NAS, customers, subscriptions', function () use ($pdo, $clients) {
            foreach ([['home20', 'Home 20', 15], ['home50', 'Home 50', 20], ['home100', 'Home 100', 30], ['biz200', 'Business 200', 60]] as [$group, $name, $price]) {
                $pdo->exec("INSERT INTO service_metadata (group_name, name, price, currency, enabled, created_at, updated_at) VALUES ('$group', '$name', $price, 'EUR', 1, NOW(), NOW())");
            }
            for ($n = 1; $n <= 4; $n++) {
                $pdo->exec("INSERT INTO nas (nasname, shortname, type, secret) VALUES ('10.0.0.$n', 'nas$n', 'mikrotik', 's')");
            }
            $pdo->exec("INSERT INTO customer_profiles (username, first_name, last_name, phone, service_group, enabled, account_status, expiry_date, auth_method, registration_date, created_at, updated_at)
                SELECT CONCAT('user', seq), CONCAT('First', seq), CONCAT('Last', seq), CONCAT('+3556', LPAD(seq, 7, '0')),
                       ELT(1 + seq % 4, 'home20', 'home50', 'home100', 'biz200'), 1, 'active', CURDATE() + INTERVAL (seq % 30) DAY, 'pppoe', NOW(), NOW(), NOW() - INTERVAL seq SECOND
                FROM seq_1_to_$clients");
            $pdo->exec('INSERT INTO billing_accounts (legacy_customer_id, created_at) SELECT id, NOW() FROM customer_profiles');
            $pdo->exec('INSERT INTO subscriptions (customer_id, billing_account_id, policy_revision, created_at, updated_at) SELECT legacy_customer_id, id, 1, NOW(), NOW() FROM billing_accounts');
        });
        $this->timed("seed radacct history ($sessions sessions)", function () use ($pdo, $clients, $sessions) {
            $pdo->exec("INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime, acctupdatetime, acctstoptime, acctsessiontime, acctinputoctets, acctoutputoctets, acctterminatecause, framedipaddress)
                SELECT HEX(seq), MD5(seq), CONCAT('user', 1 + seq % $clients), CONCAT('10.0.0.', 1 + seq % 4),
                       NOW() - INTERVAL (1 + seq DIV $clients) DAY, NOW() - INTERVAL (1 + seq DIV $clients) DAY + INTERVAL 20 HOUR,
                       NOW() - INTERVAL (1 + seq DIV $clients) DAY + INTERVAL 20 HOUR, 72000, (seq * 7919) % 2000000000, (seq * 104729) % 20000000000,
                       'User-Request', CONCAT('100.64.', (seq % 250), '.', (seq % 200))
                FROM seq_1_to_$sessions");
        });
        $this->timed("seed open sessions ($clients, 10% stale)", function () use ($pdo, $clients) {
            $pdo->exec("INSERT INTO radacct (acctsessionid, acctuniqueid, username, nasipaddress, acctstarttime, acctupdatetime, acctsessiontime, acctinputoctets, acctoutputoctets, framedipaddress)
                SELECT CONCAT('open', seq), MD5(CONCAT('open', seq)), CONCAT('user', seq), CONCAT('10.0.0.', 1 + seq % 4), NOW() - INTERVAL 3 HOUR,
                       IF(seq % 10 = 0, NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL (seq % 120) SECOND), 10800, seq * 1000, seq * 9000,
                       CONCAT('100.65.', (seq DIV 250), '.', (seq % 250))
                FROM seq_1_to_$clients");
        });
        $this->timed("seed radpostauth ($auths)", function () use ($pdo, $clients, $auths, $days) {
            $pdo->exec("INSERT INTO radpostauth (username, pass, reply, authdate)
                SELECT CONCAT('user', 1 + seq % $clients), '', IF(seq % 20 = 0, 'Access-Reject', 'Access-Accept'), NOW() - INTERVAL (seq % ($days * 86400)) SECOND
                FROM seq_1_to_$auths");
        });
        $this->timed('seed audit log, job runs', function () use ($pdo, $clients) {
            $pdo->exec("INSERT INTO audit_logs (actor_username, action, entity_type, entity_id, after_json, created_at)
                SELECT 'operator', 'update', 'client', CONCAT('user', 1 + seq % $clients), CONCAT('{\"username\":\"user', 1 + seq % $clients, '\"}'), NOW() - INTERVAL (seq % 31536000) SECOND
                FROM seq_1_to_200000");
            $pdo->exec("INSERT INTO job_runs (job_name, state, started_at, finished_at)
                SELECT ELT(1 + seq % 2, 'customer-notifications', 'expiry'), 'succeeded', NOW() - INTERVAL seq MINUTE, NOW() - INTERVAL seq MINUTE
                FROM seq_1_to_1000000");
        });
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** Eleven months of settled history before the current (generated) month. */
    private function seedInvoiceHistory(int $clients): void
    {
        $pdo = Db::pdo();
        $this->timed('seed invoice and payment history', function () use ($pdo, $clients) {
            $invoices = $clients * 11;
            $pdo->exec("INSERT INTO billing_invoices (invoice_number, username, service_group, issue_date, due_date, subtotal, total_amount, amount_paid, balance_due, currency, status, created_at, updated_at)
                SELECT CONCAT('HIST-', seq), CONCAT('user', 1 + seq % $clients), 'home50', CURDATE() - INTERVAL (1 + seq DIV $clients) MONTH, CURDATE() - INTERVAL (1 + seq DIV $clients) MONTH + INTERVAL 1 MONTH,
                       20, 20, IF(seq % 10 = 0, 0, 20), IF(seq % 10 = 0, 20, 0), 'EUR', IF(seq % 10 = 0, 'overdue', 'paid'), NOW() - INTERVAL (1 + seq DIV $clients) MONTH, NOW()
                FROM seq_1_to_$invoices");
            $pdo->exec("INSERT INTO billing_payments (invoice_id, username, payment_date, amount, currency, method, created_at)
                SELECT id, username, issue_date, 20, 'EUR', 'cash', created_at FROM billing_invoices WHERE status = 'paid'");
        });
        $pdo->query('ANALYZE TABLE radacct, radpostauth, customer_profiles, billing_invoices, audit_logs, job_runs')->fetchAll();
    }

    public function test_capacity(): void
    {
        $clients = (int) (getenv('LOAD_CLIENTS') ?: 5000);
        $days = (int) (getenv('LOAD_DAYS') ?: 365);
        $this->report(PHP_EOL . "Capacity: $clients clients, $days days of history");
        $this->seedYear($clients, $days);
        $this->report('Jobs:');
        $this->timed('frweb:generate-billing (first month, all clients)', fn () => Artisan::call('frweb:generate-billing'));
        $this->report('    ' . trim(Artisan::output()));
        $this->timed('frweb:generate-billing (rerun, nothing due)', fn () => Artisan::call('frweb:generate-billing'));
        $this->timed('frweb:process-customer-notifications', fn () => Artisan::call('frweb:process-customer-notifications'));
        $this->timed('frweb:sync-expired', fn () => Artisan::call('frweb:sync-expired'));
        $this->seedInvoiceHistory($clients);
        $this->actingAsOperator();
        $mid = 'user' . intdiv($clients, 2);

        Db::pdo()->exec("SET GLOBAL log_output='TABLE', GLOBAL long_query_time=0.05, SESSION long_query_time=0.05, GLOBAL slow_query_log=1, SESSION slow_query_log=1");
        Db::pdo()->exec('TRUNCATE mysql.slow_log');
        $this->report('Pages (single request each, warm):');
        $pages = [
            '/dashboard', '/fragments/dashboard-kpis', '/fragments/dashboard-nas', '/fragments/dashboard-rejects',
            '/clients', '/clients?page=100', '/clients?status=online', '/clients?status=offline', '/clients?status=expired', '/clients?q=Last2500', '/clients?q=100.65.3',
            '/clients?nas=10.0.0.2', "/clients/$mid/edit", "/clients/$mid/overview", "/clients/$mid/diagnostics", '/clients/export',
            '/billing', '/billing?status=overdue', '/billing?q=user2500', '/billing/export',
            '/reports', '/logs', '/monitoring', '/monitoring/live', '/monitoring/server/live', '/nas', '/nas/1/monitor/live', '/operations',
        ];
        $this->get('/dashboard'); // warm-up
        $slow = [];
        foreach ($pages as $path) {
            $ms = $this->timed($path, function () use ($path) {
                $response = $this->get($path);
                $this->assertContains($response->getStatusCode(), [200, 302], $path);
                if (method_exists($response->baseResponse, 'sendContent') && str_contains($path, 'export')) {
                    $response->streamedContent();
                }
            });
            if ($ms > 1000) {
                $slow[] = $path;
            }
        }

        $this->timed('frweb:prune-job-runs (1M rows, keep 30 days)', fn () => Artisan::call('frweb:prune-job-runs'));
        $this->report('    ' . trim(Artisan::output()));
        $this->report('Slow (>1 s): ' . ($slow ? implode(', ', $slow) : 'none'));
        Db::pdo()->exec('SET GLOBAL slow_query_log=0');
        $this->report('Slowest queries (>50 ms):');
        $worst = [];
        foreach (Db::select('SELECT TIME_TO_SEC(query_time) AS t, CONVERT(sql_text USING utf8mb4) AS q FROM mysql.slow_log') as $row) {
            $key = substr((string) preg_replace('/\s+/', ' ', $row['q']), 0, 170);
            $worst[$key] = max($worst[$key] ?? 0, (float) $row['t']);
        }
        arsort($worst);
        foreach (array_slice($worst, 0, 25, true) as $sql => $time) {
            $this->report(sprintf('  %6.0f ms  %s', $time * 1000, $sql));
        }
        $this->assertTrue(true);
    }
}
