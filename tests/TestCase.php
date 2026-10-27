<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Backup\BackupNow;
use App\Support\Db;
use App\Support\License;
use App\Support\MigrationRunner;
use App\Support\Security;
use App\Support\SoftwareUpdate;
use App\Support\TimeZone;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

/**
 * Feature tests run through the HTTP kernel against a private MariaDB started by tests/run.sh.
 * The schema (synthetic FreeRADIUS tables + database/sql migrations) is built once per process and
 * every table is emptied after each test (domain code manages its own PDO transactions, so tests
 * cannot wrap it in one).
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        $dir = getenv('FRWEB_TEST_DIR');
        if (!is_string($dir) || !preg_match('#^/tmp/frweb-laravel-test\.[A-Za-z0-9]+$#D', $dir)) {
            throw new RuntimeException('Run the suite with tests/run.sh (private database required).');
        }
        parent::setUp();
        if (!self::$schemaReady) {
            $this->buildSchema();
            self::$schemaReady = true;
        }
        $this->withHeader('X-Forwarded-Proto', 'https');
        // Never the real root helper: a test must not switch this server's time zone.
        TimeZone::$command = [$dir . '/no-timezone-helper'];
        BackupNow::$command = [$dir . '/no-backup-helper'];
        BackupNow::$isActive = ['false'];
        // Nor the update helper: a test must not update this server.
        SoftwareUpdate::$command = [$dir . '/no-update-helper'];
        SoftwareUpdate::$isActive = ['false'];
        SoftwareUpdate::$statusFile = $dir . '/update-status.json';
        SoftwareUpdate::$logFile = $dir . '/update.log';
        // RadiusOne Pro for every test (EditionsTest removes it): a key signed with a test-only key pair.
        License::save(self::licenceKey(['all'], date('Y-m-d', strtotime('+1 year')), clients: 0));
    }

    /** A RadiusOne key signed with this process's test key pair, which License then trusts. $clients: the limit (0 none, null left out). */
    protected static function licenceKey(array $features, string $expires, string $customer = 'Test ISP', ?int $clients = null): string
    {
        static $pair = null;
        if ($pair === null) {
            $pair = sodium_crypto_sign_keypair();
            License::$publicKeys[] = base64_encode(sodium_crypto_sign_publickey($pair));
        }
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $payload = json_encode(['v' => 1, 'id' => bin2hex(random_bytes(6)), 'customer' => $customer, 'issued' => date('Y-m-d'), 'expires' => $expires, 'features' => $features]
            + ($clients === null ? [] : ['clients' => $clients]), JSON_THROW_ON_ERROR);

        return License::PREFIX . '.' . $b64($payload) . '.' . $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair)));
    }

    protected function tearDown(): void
    {
        $pdo = Db::pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'schema_migrations'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    private function buildSchema(): void
    {
        $pdo = Db::pdo();
        if ((int) $pdo->query('SELECT @@skip_networking')->fetchColumn() !== 1) {
            throw new RuntimeException('Refusing to build the schema on a networked database server.');
        }
        $pdo->exec('DROP DATABASE IF EXISTS frweb_test');
        $pdo->exec('CREATE DATABASE frweb_test');
        $pdo->exec('USE frweb_test');
        // Synthetic, minimal FreeRADIUS tables (no daemon or NAS involved).
        foreach (['radcheck', 'radreply', 'radgroupcheck', 'radgroupreply'] as $table) {
            $owner = str_contains($table, 'group') ? 'groupname' : 'username';
            $pdo->exec("CREATE TABLE $table (id INT AUTO_INCREMENT PRIMARY KEY, $owner VARCHAR(64), attribute VARCHAR(64), op VARCHAR(2), value VARCHAR(253)) ENGINE=InnoDB");
        }
        $pdo->exec('CREATE TABLE radusergroup (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64), groupname VARCHAR(64), priority INT) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE radacct (radacctid BIGINT AUTO_INCREMENT PRIMARY KEY, acctsessionid VARCHAR(64) NOT NULL DEFAULT "", acctuniqueid VARCHAR(32) NOT NULL DEFAULT "", username VARCHAR(64) NOT NULL DEFAULT "", nasipaddress VARCHAR(15) NOT NULL DEFAULT "", nasportid VARCHAR(32) NULL, acctstarttime DATETIME NULL, acctupdatetime DATETIME NULL, acctstoptime DATETIME NULL, acctsessiontime INT UNSIGNED NULL, acctinputoctets BIGINT NULL, acctoutputoctets BIGINT NULL, calledstationid VARCHAR(50) NOT NULL DEFAULT "", callingstationid VARCHAR(50) NOT NULL DEFAULT "", acctterminatecause VARCHAR(32) NOT NULL DEFAULT "", framedipaddress VARCHAR(15) NOT NULL DEFAULT "", framedprotocol VARCHAR(32) NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE nas (id INT AUTO_INCREMENT PRIMARY KEY, nasname VARCHAR(128) NOT NULL, shortname VARCHAR(32), type VARCHAR(30) DEFAULT "other", ports INT, secret VARCHAR(60) NOT NULL DEFAULT "secret", server VARCHAR(64), community VARCHAR(50), description VARCHAR(200) DEFAULT "RADIUS Client") ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE radpostauth (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL DEFAULT "", pass VARCHAR(64) NOT NULL DEFAULT "", reply VARCHAR(32) NOT NULL DEFAULT "", authdate TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB');
        MigrationRunner::run(base_path('database/sql'));
    }

    /** Creates an operator and signs the test client in as them (2FA already satisfied). */
    protected function actingAsOperator(string $role = 'superadmin', array $attributes = []): array
    {
        $user = $this->createOperator($role, $attributes);
        $this->withSession(['user_id' => (int) $user['id'], 'session_version' => (int) $user['session_version'], 'reauth_at' => time()]);
        return $user;
    }

    protected function createOperator(string $role = 'superadmin', array $attributes = []): array
    {
        $username = $attributes['username'] ?? $role . '_' . bin2hex(random_bytes(3));
        $values = array_merge([
            'username' => $username,
            'password_hash' => password_hash($attributes['password'] ?? 'correct horse battery', PASSWORD_DEFAULT),
            'name' => ucfirst($role) . ' operator',
            'email' => $username . '@example.test',
            'role' => $role,
            'enabled' => 1,
        ], array_diff_key($attributes, ['password' => true]));
        $columns = array_keys($values);
        Db::exec('INSERT INTO portal_users (' . implode(',', $columns) . ', created_at, updated_at) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ', NOW(), NOW())', array_values($values));
        return Db::row('SELECT * FROM portal_users WHERE username=?', [$username]);
    }

    /**
     * Inserts a fixture row, filling every NOT NULL column that has no default with a neutral
     * value for its type, and returns the new id (or 0 for tables without one).
     */
    protected function insertRow(string $table, array $values): int
    {
        $columns = Db::select('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND IS_NULLABLE="NO" AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE "%auto_increment%"', [$table]);
        foreach ($columns as $column) {
            if (array_key_exists($column['COLUMN_NAME'], $values)) {
                continue;
            }
            $values[$column['COLUMN_NAME']] = match ($column['DATA_TYPE']) {
                'date' => date('Y-m-d'),
                'datetime', 'timestamp' => date('Y-m-d H:i:s'),
                'int', 'bigint', 'tinyint', 'smallint', 'mediumint', 'decimal', 'double', 'float' => 0,
                'enum' => trim(explode(',', substr($column['COLUMN_TYPE'], 5, -1))[0], "'"),
                default => '',
            };
        }
        $names = array_keys($values);
        Db::exec('INSERT INTO `' . $table . '` (`' . implode('`,`', $names) . '`) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')', array_values($values));
        return (int) Db::pdo()->lastInsertId();
    }

    /** Current TOTP code for a base32 secret (same algorithm as Security::totpCounter). */
    protected function totp(string $secret, int $offset = 0): string
    {
        $method = new \ReflectionMethod(Security::class, 'totpAt');
        return $method->invoke(null, $secret, intdiv(time(), 30) + $offset);
    }
}
