<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * NAS → Diagnostics: where the connection between a router and FreeRADIUS fails. It reads what the server can see
 * (settings, services, the firewall in force, accounting) and, through the root helper radiusone-nas-diag,
 * FreeRADIUS's log and a 60-second capture of RADIUS packets. FreeRADIUS does not log a wrong shared secret outside
 * debug mode, so the portal checks the captured packets against the saved one. Neither is ever shown.
 */
final class NasDiagnostics
{
    /**
     * Tests point this and $dir at stand-ins. Default: sudo radiusone-nas-diag, and the memory folder it writes in.
     *
     * @var list<string>|null
     */
    public static ?array $command = null;

    public static ?string $dir = null;

    /** @var array<int, true>|null ports listening on UDP; tests set them, normally from /proc/net */
    public static ?array $udpPorts = null;

    /** A problem in the log counts as current for this long; older ones only stay in the list of recent errors. */
    private const CURRENT_SECONDS = 900;

    private const LOG_WINDOW_SECONDS = 86400;

    public const CAPTURE_SECONDS = 60;

    private static function dir(): string
    {
        return self::$dir ?? '/run/radiusone-nas-diag';
    }

    /** @return list<string> */
    private static function command(string $action): array
    {
        return [...(self::$command ?? ['sudo', '-n', Paths::helper('nas-diag')]), $action];
    }

    /** radiusone-nas-diag is set up on this server. */
    public static function ready(): bool
    {
        return self::$command !== null || is_executable(Paths::helper('nas-diag'));
    }

    // ------------------------------------------------------------------------- reading FreeRADIUS's log

    /**
     * What the helper printed, grouped: one entry per kind of event, source and reason, with how often and when last.
     *
     * @return array{authLog: bool, authLogChanged: int, events: list<array<string, mixed>>}
     */
    public static function parseLog(string $output, ?int $now = null): array
    {
        $now ??= time();
        $authLog = false;
        $authLogChanged = 0;
        $events = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^#auth-log (yes|no) (\d+)$/', $line, $m)) {
                $authLog = $m[1] === 'yes';
                $authLogChanged = (int) $m[2];

                continue;
            }
            $event = self::parseLine($line);
            if ($event === null || $event['at'] < $now - self::LOG_WINDOW_SECONDS) {
                continue;
            }
            $key = $event['kind'] . '|' . $event['source'] . '|' . $event['reason'];
            if (isset($events[$key])) {
                $event['count'] = $events[$key]['count'] + 1;
                $event['at'] = max($event['at'], $events[$key]['at']);
            }
            $events[$key] = $event;
        }

        return ['authLog' => $authLog, 'authLogChanged' => $authLogChanged, 'events' => array_values($events)];
    }

    /**
     * One log line as an event, or null for anything this page does not explain.
     *
     * @return array<string, mixed>|null
     */
    public static function parseLine(string $line): ?array
    {
        if (preg_match('/^(\d+)(?:\.\d+)? .*radiusone-radius-drop: .*\bSRC=(\S+) .*\bDPT=(\d+)/', $line, $m)) {
            return self::event('firewall_drop', $m[2], (int) $m[1], 'UDP port ' . $m[3]);
        }
        if (!preg_match('/^\w{3} (\w{3} +\d+ [\d:]+ \d{4}) : (\w+): (.*)$/', $line, $m)) {
            return null;
        }
        $at = strtotime(preg_replace('/ +/', ' ', $m[1]));
        if ($at === false) {
            return null;
        }
        $text = $m[3];
        if (preg_match('/unknown client (\S+) port \d+/', $text, $x)) {
            return self::event('unknown_client', $x[1], $at, '');
        }
        if (preg_match('/Login OK: \[\] \(from client (.+?) port /', $text, $x)) {
            return self::event('login_ok', $x[1], $at, '');
        }
        if (preg_match('/(?:Login incorrect|Invalid user)(?: \((.*)\))?: \[\] \(from client (.+?) port /', $text, $x)) {
            return self::event('login_rejected', $x[2], $at, $x[1]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(string $kind, string $source, int $at, string $reason): array
    {
        return compact('kind', 'source', 'at', 'reason') + ['count' => 1];
    }

    /** @return array{available: bool, error: string, authLog: bool, authLogChanged: int, events: list<array<string, mixed>>} */
    public static function log(): array
    {
        if (!self::ready()) {
            return ['available' => false, 'error' => 'not set up', 'authLog' => false, 'authLogChanged' => 0, 'events' => []];
        }

        $read = static function (): array {
            try {
                $result = BoundedProcess::run(self::command('log'), '', 10, 2097152);
            } catch (RuntimeException $e) {
                return ['available' => false, 'error' => $e->getMessage(), 'authLog' => false, 'authLogChanged' => 0, 'events' => []];
            }
            if ($result['exit'] !== 0) {
                return ['available' => false, 'error' => 'the helper failed', 'authLog' => false, 'authLogChanged' => 0, 'events' => []];
            }

            return ['available' => true, 'error' => ''] + self::parseLog($result['output']);
        };

        // Several browsers on this page, or the NAS list, need one read every few seconds at most.
        return self::$command !== null ? $read() : MicroCache::remember('nas-diag-log', 15, $read);
    }

    /**
     * Addresses sending RADIUS requests that are not in the NAS list, most recent first.
     *
     * @param  array<string, mixed>  $log
     * @return list<array{address: string, how: string, count: int, at: string}>
     */
    public static function unknownRouters(?array $log = null): array
    {
        $log ??= self::log();
        $known = self::knownNetworks();
        $rows = [];
        foreach ($log['events'] as $event) {
            if (!in_array($event['kind'], ['unknown_client', 'firewall_drop'], true) || Firewall::inside($event['source'], $known)) {
                continue;
            }
            $row = $rows[$event['source']] ?? ['address' => $event['source'], 'refused' => 0, 'dropped' => 0, 'count' => 0, 'last' => 0];
            $row[$event['kind'] === 'unknown_client' ? 'refused' : 'dropped'] += $event['count'];
            $row['count'] += $event['count'];
            $row['last'] = max($row['last'], $event['at']);
            $rows[$event['source']] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => $b['last'] <=> $a['last']);

        return array_map(static fn (array $r): array => [
            'address' => $r['address'],
            'how' => $r['refused'] > 0 ? 'Refused by FreeRADIUS' : 'Dropped by the firewall',
            'count' => $r['count'],
            'at' => self::time($r['last']),
        ], array_slice($rows, 0, 20));
    }

    /** @return list<string> */
    private static function knownNetworks(): array
    {
        return Firewall::routers()['networks'];
    }

    private static function time(int $at): string
    {
        return date('Y-m-d H:i:s', $at);
    }

    /**
     * The events that belong to this NAS: logged by its address, or by its name once FreeRADIUS knows it.
     *
     * @param  array<string, mixed>  $nas
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private static function eventsFor(array $nas, array $events): array
    {
        $network = Firewall::network((string) $nas['nasname']);
        $names = array_filter([(string) $nas['nasname'], (string) $nas['shortname']]);

        return array_values(array_filter($events, static fn (array $e): bool => in_array($e['source'], $names, true)
            || ($network !== null && Firewall::inside($e['source'], [$network]))));
    }

    /**
     * What a log event means, for the list of recent errors.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private static function explain(array $event): ?array
    {
        [$title, $fix] = match ($event['kind']) {
            'unknown_client' => ['FreeRADIUS refused this address', 'It is not a loaded NAS. If this NAS was just saved or switched on, restart FreeRADIUS under Monitoring → RADIUS server.'],
            'firewall_drop' => ['The firewall dropped a RADIUS request', 'Check the rules under Settings → Firewall.'],
            'login_rejected' => ['A login was rejected' . ($event['reason'] !== '' ? ': ' . $event['reason'] : ''), self::rejectHint($event['reason'])],
            default => [null, null],
        };

        return $title === null ? null : ['title' => $title, 'fix' => $fix, 'count' => $event['count'], 'at' => self::time($event['at'])];
    }

    private static function rejectHint(string $reason): string
    {
        return stripos($reason, 'password') !== false || stripos($reason, 'mschap') !== false || stripos($reason, 'chap') !== false
            ? 'Usually a wrong password, but a wrong shared secret looks the same. If every customer on this router fails, check the secret first.'
            : 'See the customer\'s page under Clients for their last login and status.';
    }

    // ------------------------------------------------------------------------- the checklist

    /**
     * The checks in the order a connection depends on them. State: ok, warn, fail or info (could not tell, or nothing to judge).
     *
     * @param  array<string, mixed>  $nas  row with nas and nas_metadata columns
     * @param  array<string, mixed>  $log
     * @return list<array<string, mixed>>
     */
    public static function checks(array $nas, array $log): array
    {
        $check = static fn (string $key, string $label, string $state, string $detail): array => compact('key', 'label', 'state', 'detail');
        $checks = [];
        $address = (string) $nas['nasname'];
        $isAddress = filter_var($address, FILTER_VALIDATE_IP) !== false;

        $checks[] = !empty($nas['enabled'])
            ? $check('enabled', 'NAS is switched on', 'ok', 'FreeRADIUS may accept requests from it.')
            : $check('enabled', 'NAS is switched on', 'fail', 'It is switched off, so FreeRADIUS ignores it. Turn on "Accept RADIUS requests" on the Device tab.');

        $applied = $nas['applied_revision'] !== null && (int) $nas['applied_revision'] === (int) $nas['config_revision'];
        $checks[] = $applied
            ? $check('loaded', 'Saved settings are loaded', 'ok', 'FreeRADIUS is using the saved address and secret.')
            : $check('loaded', 'Saved settings are loaded', 'fail', 'FreeRADIUS is still using the old settings. Restart it under Monitoring → RADIUS server.');

        $checks[] = $isAddress
            ? $check('address', 'NAS IP is an address', 'ok', $address)
            : $check('address', 'NAS IP is an address', 'warn', '"' . $address . '" is a name or a network, so the checks below that need one address cannot run. The router must send from this exact address.');

        $service = ServerMonitor::services()['freeradius']['state'] ?? 'unknown';
        $checks[] = match ($service) {
            'active' => $check('service', 'FreeRADIUS is running', 'ok', 'The service is active.'),
            'unknown' => $check('service', 'FreeRADIUS is running', 'info', 'Could not read the service state.'),
            default => $check('service', 'FreeRADIUS is running', 'fail', 'The service is ' . $service . '. Start it under Monitoring → RADIUS server, or see: sudo systemctl status freeradius'),
        };

        $ports = self::$udpPorts ?? self::udpPorts();
        $missing = array_values(array_filter([1812, 1813], static fn (int $port): bool => !isset($ports[$port])));
        $checks[] = $missing === []
            ? $check('ports', 'Listening on UDP 1812 and 1813', 'ok', 'Logins on 1812, accounting on 1813.')
            : $check('ports', 'Listening on UDP 1812 and 1813', 'fail', 'Nothing listens on UDP ' . implode(' and ', $missing) . '. Check that FreeRADIUS started correctly.');

        $checks[] = self::firewallCheck($address, $isAddress, $check);

        foreach (self::logChecks($nas, $log, $check) as $row) {
            $checks[] = $row;
        }
        $checks[] = self::accountingCheck($address, $check);

        return $checks;
    }

    /**
     * @return array<string, mixed>
     */
    private static function firewallCheck(string $address, bool $isAddress, callable $check): array
    {
        $label = 'Firewall lets it through';
        $ufw = Firewall::host()['ufw_active'] ? ' ufw is also active here, and this page cannot read its rules.' : '';
        if (!$isAddress) {
            return $check('firewall', $label, 'info', 'Cannot be checked without an address.' . $ufw);
        }
        $verdict = Firewall::radiusVerdict($address);
        if ($verdict['state'] === 'off') {
            return $check('firewall', $label, $ufw !== '' ? 'info' : 'ok', 'The RadiusOne firewall is off.' . $ufw);
        }
        if ($verdict['state'] === 'allowed') {
            return $check('firewall', $label, $ufw !== '' ? 'info' : 'ok', 'Allowed by ' . $verdict['by'] . '.' . $ufw);
        }

        return $check('firewall', $label, 'fail', 'Blocked for UDP ' . implode(' and ', $verdict['ports']) . ' by ' . $verdict['by'] . '. Change the rules under Settings → Firewall. A NAS you just added joins the "routers" rule within a minute.');
    }

    /**
     * @param  array<string, mixed>  $nas
     * @param  array<string, mixed>  $log
     * @return list<array<string, mixed>>
     */
    private static function logChecks(array $nas, array $log, callable $check): array
    {
        $label = 'No connection errors in the log';
        if (!$log['available']) {
            return [$check('log', $label, 'info', 'The log cannot be read yet. Run sudo radiusone-update, or sudo bash ops/radiusone-postinstall in the app folder, once.')];
        }
        $changed = strtotime((string) ($nas['updated_at'] ?? '')) ?: 0;
        $since = max(time() - self::CURRENT_SECONDS, $changed);
        $events = self::eventsFor($nas, $log['events']);
        $current = array_filter($events, static fn (array $e): bool => $e['at'] >= $since);
        $rows = [];
        $problem = null;
        foreach (['unknown_client', 'firewall_drop'] as $kind) {
            foreach ($current as $event) {
                if ($event['kind'] === $kind) {
                    $problem = $event;
                    break 2;
                }
            }
        }
        if ($problem !== null) {
            $text = self::explain($problem);
            $rows[] = $check('log', $label, 'fail', $text['title'] . ' (' . $problem['count'] . ' times today, last at ' . $text['at'] . '). ' . $text['fix']);
        } else {
            $rows[] = $check('log', $label, 'ok', 'None in the last 15 minutes. A wrong shared secret does not show here: the packet test checks it.');
        }

        $label = 'Logins from this NAS';
        $hour = time() - 3600;
        if (!$log['authLog']) {
            $rows[] = $check('logins', $label, 'info', 'Login logging is off on this server, so logins cannot be matched to a NAS.');
        } elseif ($log['authLogChanged'] > (int) (ServerMonitor::services()['freeradius']['since'] ?? PHP_INT_MAX)) {
            $rows[] = $check('logins', $label, 'info', 'Login logging starts after the next FreeRADIUS restart (Monitoring → RADIUS server).');
        } else {
            $accepted = array_sum(array_map(static fn (array $e): int => $e['kind'] === 'login_ok' && $e['at'] >= $hour ? $e['count'] : 0, $events));
            $rejected = array_sum(array_map(static fn (array $e): int => $e['kind'] === 'login_rejected' && $e['at'] >= $hour ? $e['count'] : 0, $events));
            $last = array_reduce($events, static fn (int $at, array $e): int => in_array($e['kind'], ['login_ok', 'login_rejected'], true) ? max($at, $e['at']) : $at, 0);
            $rows[] = match (true) {
                $last === 0 => $check('logins', $label, 'info', 'No login requests in the last 24 hours.'),
                $accepted === 0 && $rejected > 0 => $check('logins', $label, 'warn', 'Nothing accepted in the last hour, ' . $rejected . ' rejected. See the recent errors below.'),
                default => $check('logins', $label, 'ok', $accepted . ' accepted and ' . $rejected . ' rejected in the last hour. Last at ' . self::time($last) . '.'),
            };
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function accountingCheck(string $address, callable $check): array
    {
        $label = 'Accounting is arriving';
        $open = Db::row('SELECT COUNT(*) AS sessions, MAX(COALESCE(acctupdatetime, acctstarttime)) AS last FROM radacct WHERE nasipaddress=? AND acctstoptime IS NULL', [$address]);
        $sessions = (int) ($open['sessions'] ?? 0);
        $last = (string) ($open['last'] ?? '');
        if ($sessions === 0) {
            $last = (string) Db::value('SELECT COALESCE(acctstoptime, acctupdatetime, acctstarttime) FROM radacct WHERE nasipaddress=? ORDER BY radacctid DESC LIMIT 1', [$address]);
            if ($last === '') {
                return $check('accounting', $label, 'info', 'No accounting from this address yet.');
            }

            return $check('accounting', $label, 'info', 'No open sessions. Last accounting at ' . $last . '.');
        }
        $age = time() - (strtotime($last) ?: 0);

        return $age > 900
            ? $check('accounting', $label, 'warn', $sessions . ' open sessions, but the last update was at ' . $last . '. Check the interim update on the router; for MikroTik: /ppp aaa set interim-update=1m.')
            : $check('accounting', $label, 'ok', $sessions . ' open sessions. Last update at ' . $last . '.');
    }

    /** @return array<int, true> */
    private static function udpPorts(): array
    {
        $ports = [];
        foreach (['/proc/net/udp', '/proc/net/udp6'] as $file) {
            foreach (array_slice(explode("\n", (string) @file_get_contents($file)), 1) as $line) {
                if (preg_match('/^\s*\d+:\s+[0-9A-Fa-f]+:([0-9A-Fa-f]{4})\s/', $line, $m)) {
                    $ports[(int) hexdec($m[1])] = true;
                }
            }
        }

        return $ports;
    }

    /**
     * @param  array<string, mixed>  $nas
     * @param  array<string, mixed>  $log
     * @return list<array<string, mixed>> this NAS's recent errors, newest first
     */
    public static function recentErrors(array $nas, array $log): array
    {
        $events = self::eventsFor($nas, $log['events']);
        usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice(array_values(array_filter(array_map(self::explain(...), $events))), 0, 20);
    }

    // ------------------------------------------------------------------------- the packet test

    /** Starts the test. @throws RuntimeException with a message for the operator */
    public static function startCapture(): void
    {
        if (!self::ready()) {
            throw new RuntimeException('The packet test is not set up on this server. Run sudo radiusone-update, or sudo bash ops/radiusone-postinstall in the app folder, once.');
        }
        $result = BoundedProcess::run(self::command('capture'), '', 15, 8192);
        if ($result['exit'] !== 0) {
            $detail = trim($result['error']) ?: trim($result['output']);
            if (str_contains($detail, 'password is required') || str_contains($detail, 'not allowed')) {
                $detail = 'The web server may not run the test helper yet. Run sudo bash ops/radiusone-postinstall in the app folder, once.';
            }
            throw new RuntimeException($detail !== '' ? $detail : 'The packet test could not be started.');
        }
    }

    /**
     * Counts per router address from tcpdump's output. A request goes to port 1812 or 1813; a reply comes from them.
     * Requests from $address are checked against $secret; nothing else from a packet is kept.
     *
     * @return array<string, array<string, int>>
     */
    public static function parseCapture(string $output, ?string $address = null, string $secret = ''): array
    {
        $peers = [];
        $hex = '';
        $signed = null;
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s+0x[0-9a-f]+:\s+([0-9a-f ]+)$/', $line, $m)) {
                $hex .= $signed !== null ? str_replace(' ', '', $m[1]) : '';

                continue;
            }
            self::tallySecret($peers, $signed, $hex, $secret);
            $hex = '';
            $signed = null;
            if (!preg_match('/^\d+\.\d+\s+(?:\S+\s+\w+\s+)?IP6?\s+(\S+)\.(\d+) > (\S+)\.(\d+):\s*(.*)$/', $line, $m)) {
                continue;
            }
            $request = in_array((int) $m[4], [1812, 1813], true);
            $reply = in_array((int) $m[2], [1812, 1813], true);
            if ($request === $reply) {
                continue;
            }
            $peer = $request ? $m[1] : $m[3];
            $port = $request ? (int) $m[4] : (int) $m[2];
            $peers[$peer] ??= ['logins' => 0, 'accepted' => 0, 'rejected' => 0, 'challenged' => 0, 'accounting' => 0, 'accountingReplies' => 0, 'otherReplies' => 0, 'secretOk' => 0, 'secretBad' => 0];
            if ($request) {
                $peers[$peer][$port === 1812 ? 'logins' : 'accounting']++;
                if ($secret !== '' && $address !== null && Firewall::inside($peer, [$address])) {
                    $signed = $peer;
                }
            } elseif (str_contains($m[5], 'Access-Accept')) {
                $peers[$peer]['accepted']++;
            } elseif (str_contains($m[5], 'Access-Reject')) {
                $peers[$peer]['rejected']++;
            } elseif (str_contains($m[5], 'Access-Challenge')) {
                $peers[$peer]['challenged']++;
            } elseif (str_contains($m[5], 'Accounting-Response')) {
                $peers[$peer]['accountingReplies']++;
            } else {
                $peers[$peer]['otherReplies']++;
            }
        }
        self::tallySecret($peers, $signed, $hex, $secret);

        return $peers;
    }

    /** @param array<string, array<string, int>> $peers */
    private static function tallySecret(array &$peers, ?string $peer, string $hex, string $secret): void
    {
        if ($peer === null || $hex === '') {
            return;
        }
        $match = self::secretMatches((string) hex2bin(strlen($hex) % 2 ? substr($hex, 0, -1) : $hex), $secret);
        if ($match !== null) {
            $peers[$peer][$match ? 'secretOk' : 'secretBad']++;
        }
    }

    /**
     * Whether a captured IP packet holding a RADIUS request was signed with this secret. Accounting requests always
     * are; login requests only when they carry a Message-Authenticator.
     *
     * @return bool|null null: it cannot be told from this packet
     */
    public static function secretMatches(string $ip, string $secret): ?bool
    {
        if ($ip === '') {
            return null;
        }
        $version = ord($ip[0]) >> 4;
        $skip = ($version === 4 ? (ord($ip[0]) & 0x0F) * 4 : ($version === 6 ? 40 : -1)) + 8;
        if ($skip < 8) {
            return null;
        }
        $packet = substr($ip, $skip);
        if (strlen($packet) < 20) {
            return null;
        }
        $length = ord($packet[2]) * 256 + ord($packet[3]);
        if ($length < 20 || strlen($packet) < $length) {
            return null;
        }
        $packet = substr($packet, 0, $length);
        $code = ord($packet[0]);
        if ($code === 4) {
            return hash_equals(substr($packet, 4, 16), md5(substr($packet, 0, 4) . str_repeat("\0", 16) . substr($packet, 20) . $secret, true));
        }
        if ($code !== 1) {
            return null;
        }
        for ($at = 20; $at + 2 <= $length; $at += $size) {
            $size = ord($packet[$at + 1]);
            if ($size < 2) {
                return null;
            }
            if (ord($packet[$at]) === 80 && $size === 18) {
                $zeroed = substr_replace($packet, str_repeat("\0", 16), $at + 2, 16);

                return hash_equals(substr($packet, $at + 2, 16), hash_hmac('md5', $zeroed, $secret, true));
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $nas
     * @param  array<string, mixed>  $log
     * @return array<string, mixed> the test's state and, as packets arrive, what they mean for this NAS
     */
    public static function captureResult(array $nas, array $log): array
    {
        $started = (int) @file_get_contents(self::dir() . '/capture.started');
        if ($started === 0) {
            return ['state' => 'none'];
        }
        $done = is_file(self::dir() . '/capture.done');
        $elapsed = time() - $started;
        // A helper that went silent must not look as if it runs for ever.
        $stuck = !$done && $elapsed > self::CAPTURE_SECONDS + 30;
        $network = Firewall::network((string) $nas['nasname']);
        $peers = self::parseCapture((string) @file_get_contents(self::dir() . '/capture.txt'), $network, (string) ($nas['secret'] ?? ''));

        $counts = [];
        $others = [];
        $known = self::knownNetworks();
        foreach ($peers as $peer => $row) {
            if ($network !== null && Firewall::inside($peer, [$network])) {
                foreach ($row as $name => $value) {
                    $counts[$name] = ($counts[$name] ?? 0) + $value;
                }
            } elseif (!Firewall::inside($peer, $known) && $row['logins'] + $row['accounting'] > 0) {
                $others[] = ['address' => $peer, 'requests' => $row['logins'] + $row['accounting']];
            }
        }
        $counts += ['logins' => 0, 'accepted' => 0, 'rejected' => 0, 'challenged' => 0, 'accounting' => 0, 'accountingReplies' => 0, 'otherReplies' => 0, 'secretOk' => 0, 'secretBad' => 0];
        usort($others, static fn (array $a, array $b): int => $b['requests'] <=> $a['requests']);

        return [
            'state' => $stuck ? 'failed' : ($done ? 'done' : 'running'),
            'startedAt' => self::time($started),
            'remaining' => $done || $stuck ? 0 : max(0, self::CAPTURE_SECONDS - $elapsed),
            'counts' => $counts,
            'others' => array_slice($others, 0, 10),
            'verdict' => self::verdict($counts, $others, $nas, $log),
        ];
    }

    /**
     * What the packets say about the connection, as one conclusion.
     *
     * @param  array<string, int>  $counts
     * @param  list<array{address: string, requests: int}>  $others
     * @param  array<string, mixed>  $nas
     * @param  array<string, mixed>  $log
     * @return array{tone: string, title: string, detail: string}
     */
    public static function verdict(array $counts, array $others, array $nas, array $log): array
    {
        $say = static fn (string $tone, string $title, string $detail): array => compact('tone', 'title', 'detail');
        $requests = $counts['logins'] + $counts['accounting'];
        $replies = $counts['accepted'] + $counts['rejected'] + $counts['challenged'] + $counts['accountingReplies'] + $counts['otherReplies'];
        $address = (string) $nas['nasname'];

        if ($requests === 0) {
            if ($others !== []) {
                return $say('warning', 'Nothing arrived from ' . $address . ', but other addresses sent requests', 'If one of the addresses below is this router, it sends from a different address than the one saved, often because of NAT. On the router, set the source address of the RADIUS client, or use that address here.');
            }

            return $say('danger', 'Nothing arrived from ' . $address, 'The requests do not reach this server. Check the RADIUS client on the router (server address, service, source address), the route, and any firewall between the router and this server.');
        }

        if ($counts['secretBad'] > 0) {
            return $say('danger', 'The shared secret is wrong', $counts['secretBad'] . ' of the requests were signed with a different secret from the one saved here. Enter the same secret on the router and on the Device tab.');
        }

        if ($replies === 0) {
            $verdict = filter_var($address, FILTER_VALIDATE_IP) !== false ? Firewall::radiusVerdict($address) : ['state' => 'off'];
            if ($verdict['state'] === 'blocked') {
                return $say('danger', 'Requests arrive, but the firewall blocks them', 'Blocked by ' . $verdict['by'] . '. Change the rules under Settings → Firewall.');
            }
            $current = array_filter(self::eventsFor($nas, $log['events']), static fn (array $e): bool => $e['at'] >= time() - self::CURRENT_SECONDS);
            foreach ($current as $event) {
                if ($event['kind'] === 'unknown_client') {
                    $text = self::explain($event);

                    return $say('danger', 'Requests arrive, but ' . lcfirst($text['title']), $text['fix']);
                }
            }

            return $say('danger', 'Requests arrive, but FreeRADIUS does not answer', 'Likely causes: the NAS is not loaded yet (restart FreeRADIUS), ufw blocks the port, FreeRADIUS is overloaded, or the router leaves out Message-Authenticator after sending it before.' . ($counts['secretOk'] > 0 ? ' The shared secret is correct.' : ' The shared secret could not be checked, because the requests carry no signature.'));
        }

        if ($counts['accepted'] === 0 && $counts['rejected'] > 0) {
            return $say('warning', 'The connection works, but FreeRADIUS rejects the logins', 'Usually a wrong password, or an account that is suspended or expired. ' . ($counts['secretOk'] > 0 ? 'The shared secret is correct.' : 'A wrong shared secret can look the same, and these requests carry no signature to check it. If every customer on this router fails, enter the secret again on both sides.'));
        }

        if ($counts['accounting'] > 0 && $counts['accountingReplies'] === 0) {
            return $say('warning', 'Accounting requests get no reply', 'FreeRADIUS does not answer on UDP 1813. Check that the NAS is loaded, and the service under Monitoring → RADIUS server.');
        }

        if ($counts['accounting'] === 0 && $counts['logins'] > 0) {
            return $say('success', 'Logins work', 'No accounting arrived during the test, which can be normal with a long interim update. Online sessions need accounting; on MikroTik check /ppp aaa set accounting=yes.');
        }

        return $say('success', 'The server receives requests and replies', 'If the router still shows timeouts, the replies do not get back to it or the secret differs. On MikroTik check: /radius monitor 0.');
    }
}
