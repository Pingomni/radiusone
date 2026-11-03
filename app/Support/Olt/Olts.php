<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Db;
use App\Support\Secrets;
use App\Support\Snmp;
use RuntimeException;

/** The list of OLTs and their saved settings. Passwords stay sealed and are never sent to the browser. */
final class Olts
{
    /** Only models whose commands and values have been built for. */
    public const MODELS = ['huawei_ma5800_x7' => 'Huawei MA5800-X7'];

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Db::row('SELECT * FROM olts WHERE id=?', [$id]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed> the settings, ready to save. Throws a readable error for anything wrong.
     */
    public static function validated(array $input, ?array $stored): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new RuntimeException('Name is required (up to 80 characters).');
        }
        $model = (string) ($input['model'] ?? '');
        if (!isset(self::MODELS[$model])) {
            throw new RuntimeException('Choose a supported OLT model.');
        }
        $host = trim((string) ($input['host'] ?? ''));
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Enter the OLT\'s management IP address.');
        }
        $version = (string) ($input['snmp_version'] ?? '2c');
        if (!in_array($version, ['1', '2c', '3'], true)) {
            throw new RuntimeException('Choose SNMP v3, v2c or v1.');
        }
        $secret = static function (string $field, string $column, string $label) use ($input, $stored): ?string {
            $typed = (string) ($input[$field] ?? '');
            if ($typed === '') {
                return $stored[$column] ?? null;
            }
            if (strlen($typed) > Secrets::MAX_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $typed)) {
                throw new RuntimeException($label . ' is too long or has characters that cannot be used.');
            }
            return Secrets::encrypt($typed);
        };
        $values = [
            'name' => $name, 'model' => $model, 'host' => $host,
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'snmp_version' => $version,
            'snmp_port' => self::port($input['snmp_port'] ?? 161, 'SNMP'),
            'snmp_community' => $secret('snmp_community', 'snmp_community', 'SNMP community'),
            'v3_user' => trim((string) ($input['v3_user'] ?? '')),
            'v3_auth_protocol' => strtolower((string) (($input['v3_auth_protocol'] ?? '') ?: 'none')),
            'v3_auth_password' => $secret('v3_auth_password', 'v3_auth_password', 'Authentication password'),
            'v3_priv_protocol' => strtolower((string) (($input['v3_priv_protocol'] ?? '') ?: 'none')),
            'v3_priv_password' => $secret('v3_priv_password', 'v3_priv_password', 'Privacy password'),
            'v3_context' => trim((string) ($input['v3_context'] ?? '')),
            'telnet_enabled' => empty($input['telnet_enabled']) ? 0 : 1,
            'telnet_port' => self::port($input['telnet_port'] ?? 23, 'Telnet'),
            'telnet_user' => $secret('telnet_user', 'telnet_user', 'Telnet user'),
            'telnet_password' => $secret('telnet_password', 'telnet_password', 'Telnet password'),
        ];
        if ($version === '3') {
            Snmp::validateUsm(self::usm($values));
        } elseif (Secrets::reveal($values['snmp_community']) === '') {
            throw new RuntimeException('Enter the read-only SNMP community.');
        }
        if ($values['telnet_enabled'] && (Secrets::reveal($values['telnet_user']) === '' || Secrets::reveal($values['telnet_password']) === '')) {
            throw new RuntimeException('Telnet needs a user and a password. Turn it off to use SNMP only.');
        }
        return $values;
    }

    private static function port(mixed $value, string $label): int
    {
        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException($label . ' port must be between 1 and 65535.');
        }
        return $port;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(?int $id, array $values): int
    {
        $columns = array_keys($values);
        if ($id !== null) {
            Db::exec('UPDATE olts SET ' . implode(',', array_map(static fn ($c) => $c . '=?', $columns)) . ',updated_at=NOW() WHERE id=?', [...array_values($values), $id]);
            return $id;
        }
        Db::exec('INSERT INTO olts (' . implode(',', $columns) . ',created_at,updated_at) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ',NOW(),NOW())', array_values($values));
        return (int) Db::pdo()->lastInsertId();
    }

    /**
     * @param  array<string, mixed>  $olt
     * @return array<string, mixed>
     */
    private static function usm(array $olt): array
    {
        return [
            'user' => (string) ($olt['v3_user'] ?? ''), 'context' => (string) ($olt['v3_context'] ?? ''),
            'auth' => (string) ($olt['v3_auth_protocol'] ?? 'none'), 'auth_pass' => Secrets::reveal($olt['v3_auth_password'] ?? ''),
            'priv' => (string) ($olt['v3_priv_protocol'] ?? 'none'), 'priv_pass' => Secrets::reveal($olt['v3_priv_password'] ?? ''),
        ];
    }

    /**
     * An OLT answers slowly while it reads optical levels, so this waits longer than the NAS poller.
     *
     * @param  array<string, mixed>  $olt
     */
    public static function snmp(array $olt, float $budget = 600.0, float $timeout = 3.0, int $retries = 2): Snmp
    {
        return new Snmp(
            (string) $olt['host'],
            Secrets::reveal($olt['snmp_community'] ?? ''),
            (string) $olt['snmp_version'],
            (int) $olt['snmp_port'],
            $timeout,
            $retries,
            $budget,
            $olt['snmp_version'] === '3' ? self::usm($olt) : [],
        );
    }

    /**
     * @param  array<string, mixed>  $olt
     */
    public static function cli(array $olt, float $budget = 240.0): OltCli
    {
        return new OltCli((string) $olt['host'], (int) $olt['telnet_port'], Secrets::reveal($olt['telnet_user'] ?? ''), Secrets::reveal($olt['telnet_password'] ?? ''), $budget);
    }

    /**
     * Tries the saved settings: reads the system name over SNMP and logs in over telnet.
     *
     * @param  array<string, mixed>  $olt
     * @return array{ok:bool,lines:list<string>}
     */
    public static function test(array $olt): array
    {
        $lines = [];
        $ok = true;
        try {
            $description = (string) (self::snmp($olt, 12.0, 2.0, 1)->get(['1.3.6.1.2.1.1.1.0'])['1.3.6.1.2.1.1.1.0'] ?? '');
            if ($description === '') {
                throw new RuntimeException('The OLT answered without a system description.');
            }
            $description = mb_substr(mb_scrub(trim($description), 'UTF-8'), 0, 255);
            Db::exec('UPDATE olts SET system_description=? WHERE id=?', [$description, $olt['id']]);
            $lines[] = 'SNMP works: ' . mb_substr($description, 0, 80) . '.';
            if (!preg_match('/MA5800|Huawei/i', $description)) {
                $ok = false;
                $lines[] = 'This does not look like a Huawei MA5800. Check the address.';
            }
        } catch (RuntimeException $e) {
            $ok = false;
            $lines[] = 'SNMP failed: ' . $e->getMessage();
        }
        if ($olt['telnet_enabled']) {
            try {
                self::cli($olt, 25.0)->check();
                $lines[] = 'Telnet login works.';
            } catch (RuntimeException $e) {
                $ok = false;
                $lines[] = 'Telnet failed: ' . $e->getMessage();
            }
        }
        return ['ok' => $ok, 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed>  $onu
     */
    public static function position(array $onu): string
    {
        return $onu['frame'] . '/' . $onu['slot'] . '/' . $onu['port'] . ':' . $onu['ont_id'];
    }
}
