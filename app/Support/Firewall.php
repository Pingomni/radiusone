<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Settings → Firewall: this server's firewall, as nftables rules managed from the portal (as DNSOne does it).
 *
 * RadiusOne owns one table, `inet radiusone`, with an input chain that drops what it does not allow:
 *
 *   always   established connections, loopback, the ICMP that IPv4 and IPv6 need, and SSH and the portal
 *            from the management networks; no rule below can take these away
 *   rules    the operator's rules, in order. The first time the page opens they come from a template (the
 *            portal from anywhere, RADIUS from your routers); they can be changed, and the template restored
 *   policy   everything else is dropped
 *
 * The firewall starts off. Edits are a draft while it is on, until Apply. Turning it on or applying stores a
 * snapshot (setting firewall_active) and leaves a request for radiusone-firewall (root, through
 * radiusone-firewall.path): it renders the snapshot itself, checks it with `nft -c`, applies it in one
 * transaction and waits for the portal to confirm it can still reach the server. Without a confirmation within
 * $confirmSeconds it puts the previous rules back. At boot, radiusone-firewall.service loads the last confirmed
 * rules. `sudo radiusone-firewall off` removes the table from a shell.
 *
 * The web server never writes nftables rules. Root renders from validated fields only (addresses, port numbers,
 * fixed words), so nothing in the database reaches nft as text. Routers added or removed later, and rules that
 * expire, are picked up by the scheduler (sync) without a new Apply.
 */
final class Firewall
{
    public const TABLE = 'radiusone';

    public const ROUTERS = 'routers';

    public const MAX_RULES = 100;

    public const ACTIONS = ['accept', 'drop', 'reject'];

    public const PROTOCOLS = ['tcp', 'udp', 'tcp+udp', 'icmp', 'any'];

    /** The starting rules, also what "Restore the template" brings back. */
    public const TEMPLATE = [
        ['action' => 'accept', 'protocol' => 'tcp', 'source' => '', 'ports' => '80,443', 'comment' => 'The portal, and renewing its certificate'],
        ['action' => 'accept', 'protocol' => 'udp', 'source' => self::ROUTERS, 'ports' => '1812,1813', 'comment' => 'RADIUS from your routers'],
    ];

    private const CONFIG = 'firewall_config';

    private const ACTIVE = 'firewall_active';

    private const PREVIOUS = 'firewall_previous';

    /** A helper that said nothing for this long is gone: the portal may apply again. */
    private const STALE_SECONDS = 200;

    /** Tests point these at a temporary directory and a stand-in for nft. */
    public static ?string $requestDir = null;

    public static ?string $statusDir = null;

    /** @var list<string> */
    public static array $nft = ['/usr/sbin/nft'];

    public static int $confirmSeconds = 60;

    // ------------------------------------------------------------------------- configuration and rules

    /** The first time the page opens: the firewall off, the template's rules, and this visitor as management. */
    public static function ensureTemplate(string $clientIp): void
    {
        if (Settings::value(self::CONFIG, '') !== '') {
            return;
        }
        $own = self::network($clientIp);
        self::saveConfig(['enabled' => false, 'management_networks' => $own !== null && !self::isLoopback($clientIp) ? [$own] : []]);
        if ((int) Db::value('SELECT COUNT(*) FROM firewall_rules') === 0) {
            self::insertTemplate('template');
        }
    }

    /** @return array{enabled:bool,management_networks:list<string>} */
    public static function config(): array
    {
        $saved = json_decode(Settings::value(self::CONFIG, ''), true);

        return [
            'enabled' => (bool) ($saved['enabled'] ?? false),
            'management_networks' => array_values(array_map('strval', (array) ($saved['management_networks'] ?? []))),
        ];
    }

    /** @param array{enabled:bool,management_networks:list<string>} $config */
    private static function saveConfig(array $config): void
    {
        Settings::save(self::CONFIG, json_encode($config, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $lines  addresses or networks; # starts a note
     * @return list<string> what was stored
     */
    public static function saveManagement(array $lines, string $clientIp): array
    {
        $networks = [];
        foreach ($lines as $line) {
            $text = trim(explode('#', $line)[0]);
            if ($text === '') {
                continue;
            }
            $network = self::network($text);
            if ($network === null) {
                throw new InvalidArgumentException('Not an address or network: ' . mb_substr($text, 0, 60));
            }
            if (str_ends_with($network, '/0')) {
                throw new InvalidArgumentException('List your management networks; the whole internet is not one.');
            }
            $networks[$network] = true;
        }
        $networks = array_keys($networks);
        if (count($networks) > 200) {
            throw new InvalidArgumentException('At most 200 management networks.');
        }
        $config = self::config();
        if ($config['enabled'] && !self::inside($clientIp, $networks)) {
            throw new InvalidArgumentException('Your address ' . $clientIp . ' would not be in the management networks, and SSH and the portal are always open to them. Add it, or turn the firewall off first.');
        }
        self::saveConfig(['enabled' => $config['enabled'], 'management_networks' => $networks]);

        return $networks;
    }

    /** @return list<array<string, mixed>> */
    public static function rules(): array
    {
        $now = date('Y-m-d H:i:s');

        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'position' => (int) $r['position'],
            'action' => (string) $r['action'],
            'protocol' => (string) $r['protocol'],
            'source' => (string) $r['source'],
            'ports' => (string) $r['ports'],
            'comment' => (string) $r['comment'],
            'enabled' => (bool) $r['enabled'],
            'created_by' => (string) $r['created_by'],
            'expires_at' => $r['expires_at'] === null ? null : (string) $r['expires_at'],
            'expired' => $r['expires_at'] !== null && (string) $r['expires_at'] <= $now,
        ], Db::select('SELECT * FROM firewall_rules ORDER BY position, id')));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{action:string,protocol:string,source:string,ports:string}
     */
    public static function cleanRule(array $values, string $clientIp = ''): array
    {
        $action = (string) ($values['action'] ?? '');
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Choose accept, drop or reject.');
        }
        $protocol = (string) ($values['protocol'] ?? 'tcp');
        if (!in_array($protocol, self::PROTOCOLS, true)) {
            throw new InvalidArgumentException('Choose TCP, UDP, TCP and UDP, ICMP or any protocol.');
        }
        $source = strtolower(trim((string) ($values['source'] ?? '')));
        if ($source !== '' && $source !== self::ROUTERS) {
            $network = self::network($source);
            if ($network === null) {
                throw new InvalidArgumentException('The source must be an address, a network, "routers", or empty for anyone.');
            }
            $source = str_ends_with($network, '/0') ? '' : $network;
        }
        $ports = self::cleanPorts((string) ($values['ports'] ?? ''), $protocol);
        if ($action !== 'accept' && $source === '' && $ports === '') {
            throw new InvalidArgumentException('A rule that blocks everything from everyone would cut the server off. Give a source or ports.');
        }
        if ($action !== 'accept' && $source !== '' && $source !== self::ROUTERS && $clientIp !== '' && !self::isLoopback($clientIp) && self::inside($clientIp, [$source]) && $ports === '') {
            throw new InvalidArgumentException('This rule would block your own address, ' . $clientIp . '.');
        }

        return ['action' => $action, 'protocol' => $protocol, 'source' => $source, 'ports' => $ports];
    }

    /** "22", "80,443", "1000-2000" in canonical form; empty is every port, and always empty for ICMP and any. */
    public static function cleanPorts(string $raw, string $protocol): string
    {
        $value = str_replace(' ', '', $raw);
        if ($value === '' || $protocol === 'icmp' || $protocol === 'any') {
            return '';
        }
        $parts = [];
        foreach (explode(',', $value) as $part) {
            if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/D', $part, $m)) {
                throw new InvalidArgumentException('Ports are numbers, ranges and commas: 22, or 80,443, or 1000-2000.');
            }
            $low = (int) $m[1];
            $high = (int) ($m[2] ?? $m[1]);
            if ($low < 1 || $high > 65535 || $low > $high) {
                throw new InvalidArgumentException('Ports go from 1 to 65535, and a range goes from low to high.');
            }
            $parts[] = $low === $high ? (string) $low : "$low-$high";
        }
        if (count($parts) > 32) {
            throw new InvalidArgumentException('At most 32 ports or ranges in one rule.');
        }

        return implode(',', $parts);
    }

    /** @param array<string, mixed> $values */
    public static function addRule(array $values, string $by, string $clientIp): int
    {
        $clean = self::cleanRule($values, $clientIp);
        if ((int) Db::value('SELECT COUNT(*) FROM firewall_rules') >= self::MAX_RULES) {
            throw new InvalidArgumentException('At most ' . self::MAX_RULES . ' rules.');
        }
        $minutes = max(0, min(525600, (int) ($values['minutes'] ?? 0)));
        Db::exec(
            'INSERT INTO firewall_rules (position, action, protocol, source, ports, comment, enabled, created_by, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [(int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM firewall_rules'), $clean['action'], $clean['protocol'], $clean['source'], $clean['ports'],
                mb_substr(trim((string) ($values['comment'] ?? '')), 0, 255), mb_substr($by, 0, 64), date('Y-m-d H:i:s'),
                $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null]
        );

        return (int) Db::value('SELECT LAST_INSERT_ID()');
    }

    /** @param array<string, mixed> $values */
    public static function updateRule(int $id, array $values, string $clientIp): void
    {
        self::rule($id);
        $clean = self::cleanRule($values, $clientIp);
        Db::exec(
            'UPDATE firewall_rules SET action=?, protocol=?, source=?, ports=?, comment=? WHERE id=?',
            [$clean['action'], $clean['protocol'], $clean['source'], $clean['ports'], mb_substr(trim((string) ($values['comment'] ?? '')), 0, 255), $id]
        );
    }

    public static function toggleRule(int $id): void
    {
        self::rule($id);
        Db::exec('UPDATE firewall_rules SET enabled = 1 - enabled WHERE id=?', [$id]);
    }

    public static function moveRule(int $id, int $by): void
    {
        self::rule($id);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], Db::select('SELECT id FROM firewall_rules ORDER BY position, id'));
        $from = (int) array_search($id, $ids, true);
        $to = $from + ($by < 0 ? -1 : 1);
        if (!isset($ids[$to])) {
            return;
        }
        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
        foreach ($ids as $position => $rule) {
            Db::exec('UPDATE firewall_rules SET position=? WHERE id=?', [$position + 1, $rule]);
        }
    }

    public static function deleteRule(int $id): void
    {
        self::rule($id);
        Db::exec('DELETE FROM firewall_rules WHERE id=?', [$id]);
    }

    /** Replaces the rules with the template. */
    public static function restoreTemplate(string $by): void
    {
        Db::exec('DELETE FROM firewall_rules');
        self::insertTemplate($by);
    }

    private static function insertTemplate(string $by): void
    {
        foreach (self::TEMPLATE as $position => $rule) {
            Db::exec(
                'INSERT INTO firewall_rules (position, action, protocol, source, ports, comment, enabled, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                [$position + 1, $rule['action'], $rule['protocol'], $rule['source'], $rule['ports'], $rule['comment'], mb_substr($by, 0, 64), date('Y-m-d H:i:s')]
            );
        }
    }

    /** @return array<string, mixed> */
    private static function rule(int $id): array
    {
        return Db::row('SELECT * FROM firewall_rules WHERE id=?', [$id]) ?? throw new InvalidArgumentException('That rule no longer exists.');
    }

    /**
     * The routers' addresses (the RADIUS clients in the nas table), and the names nft cannot use.
     *
     * @return array{networks:list<string>,names:list<string>}
     */
    public static function routers(): array
    {
        $networks = [];
        $names = [];
        foreach (Db::select('SELECT nasname FROM nas ORDER BY id') as $row) {
            $name = trim((string) $row['nasname']);
            $network = self::network($name);
            if ($network !== null) {
                $networks[$network] = true;
            } elseif ($name !== '') {
                $names[$name] = true;
            }
        }

        return ['networks' => array_keys($networks), 'names' => array_map('strval', array_keys($names))];
    }

    /** @return array<string, mixed> what Apply would put in force */
    public static function draft(): array
    {
        return [
            'config' => self::config(),
            'rules' => array_map(static fn (array $r): array => array_intersect_key($r, array_flip(['id', 'action', 'protocol', 'source', 'ports', 'enabled', 'expires_at'])), self::rules()),
            'routers' => self::routers()['networks'],
        ];
    }

    /** @return array<string, mixed>|null the snapshot in force */
    public static function active(): ?array
    {
        $active = json_decode(Settings::value(self::ACTIVE, ''), true);

        return is_array($active) ? $active : null;
    }

    /** Changes not in force yet (routers follow by themselves, so they do not count). */
    public static function pending(): bool
    {
        $live = self::active();
        if ($live === null || !self::config()['enabled']) {
            return false;
        }
        $draft = self::draft();

        return ($live['config'] ?? null) != $draft['config'] || ($live['rules'] ?? null) != $draft['rules'];
    }

    // ------------------------------------------------------------------------- rendering (also run as root)

    /** A canonical address/prefix (host bits cleared), or null. */
    public static function network(string $raw): ?string
    {
        $parts = explode('/', trim($raw));
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $binary = (string) inet_pton($parts[0]);
        $bits = strlen($binary) * 8;
        if (isset($parts[1]) && !preg_match('/^\d{1,3}$/D', $parts[1])) {
            return null;
        }
        $length = isset($parts[1]) ? (int) $parts[1] : $bits;
        if ($length > $bits) {
            return null;
        }
        $masked = '';
        foreach (str_split($binary) as $i => $byte) {
            $keep = max(0, min(8, $length - $i * 8));
            $masked .= chr(ord($byte) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return inet_ntop($masked) . '/' . $length;
    }

    /** @param list<string> $networks */
    public static function inside(string $address, array $networks): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $bits = strlen((string) inet_pton($address)) * 8;
        foreach ($networks as $network) {
            [$base, $length] = explode('/', (string) self::network((string) $network) . '/');
            if ($base !== '' && strlen((string) inet_pton($base)) * 8 === $bits && self::network($address . '/' . $length) === $base . '/' . $length) {
                return true;
            }
        }

        return false;
    }

    private static function isLoopback(string $address): bool
    {
        return $address === '::1' || str_starts_with($address, '127.');
    }

    /** @param list<string> $networks */
    private static function allowedFrom(string $address, array $networks): bool
    {
        return self::isLoopback($address) || self::inside($address, $networks);
    }

    /** A clean port list as nft wants it. */
    private static function portSet(string $clean): string
    {
        return str_contains($clean, ',') ? '{ ' . str_replace(',', ', ', $clean) . ' }' : $clean;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<string>  $routers4
     * @param  list<string>  $routers6
     * @return list<string>
     */
    private static function ruleLines(array $rule, array $routers4, array $routers6, string $now): array
    {
        if (empty($rule['enabled']) || (!empty($rule['expires_at']) && (string) $rule['expires_at'] <= $now)) {
            return [];
        }
        $action = (string) ($rule['action'] ?? '');
        $protocol = (string) ($rule['protocol'] ?? '');
        if (!in_array($action, self::ACTIONS, true) || !in_array($protocol, self::PROTOCOLS, true)) {
            throw new RuntimeException('Rule ' . (int) ($rule['id'] ?? 0) . ': bad action or protocol.');
        }
        $source = (string) ($rule['source'] ?? '');
        $matches = [['ip', ''], ['ip6', '']];
        if ($source === self::ROUTERS) {
            $matches = [];
            if ($routers4 !== []) {
                $matches[] = ['ip', 'ip saddr @routers4 '];
            }
            if ($routers6 !== []) {
                $matches[] = ['ip6', 'ip6 saddr @routers6 '];
            }
        } elseif ($source !== '') {
            $network = self::network($source) ?? throw new RuntimeException('Rule ' . (int) ($rule['id'] ?? 0) . ': bad source.');
            $family = str_contains($network, ':') ? 'ip6' : 'ip';
            $matches = [[$family, "$family saddr $network "]];
        }
        $ports = self::cleanPorts((string) ($rule['ports'] ?? ''), $protocol);
        $set = $ports === '' ? '' : self::portSet($ports);
        $tag = 'comment "rule ' . (int) ($rule['id'] ?? 0) . '"';
        $lines = [];
        if ($source === '' && $protocol !== 'icmp') {
            $matches = [['', '']];
        }
        foreach ($matches as [$family, $match]) {
            $lines[] = match ($protocol) {
                'tcp', 'udp' => $set !== '' ? "$match$protocol dport $set $action $tag" : "{$match}meta l4proto $protocol $action $tag",
                'tcp+udp' => $set !== '' ? "{$match}meta l4proto { tcp, udp } th dport $set $action $tag" : "{$match}meta l4proto { tcp, udp } $action $tag",
                'icmp' => "{$match}meta l4proto " . ($family === 'ip' ? 'icmp' : 'ipv6-icmp') . " $action $tag",
                default => $match !== '' ? "$match$action $tag" : "meta nfproto { ipv4, ipv6 } $action $tag",
            };
        }

        return $lines;
    }

    /**
     * The nft script for a snapshot; null or switched off: the table removed.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @param  list<int>  $sshPorts
     */
    public static function render(?array $snapshot, array $sshPorts = [22], ?string $now = null): string
    {
        $now ??= date('Y-m-d H:i:s');
        $table = 'table inet ' . self::TABLE;
        $head = ['# Generated by RadiusOne (Settings → Firewall). Do not edit: the next Apply replaces it.', $table, 'delete ' . $table];
        if ($snapshot === null || empty($snapshot['config']['enabled'])) {
            return implode("\n", $head) . "\n";
        }
        $canonical = static function (array $list): array {
            $out = [];
            foreach ($list as $item) {
                $out[] = self::network((string) $item) ?? throw new RuntimeException('Bad address in the snapshot.');
            }

            return $out;
        };
        $split = static fn (array $list, bool $six): array => array_values(array_filter($list, static fn (string $n): bool => str_contains($n, ':') === $six));
        $management = $canonical((array) ($snapshot['config']['management_networks'] ?? []));
        if ($management === []) {
            throw new RuntimeException('List at least one management network.');
        }
        $routers = $canonical((array) ($snapshot['routers'] ?? []));
        $sets = [
            'management4' => ['ipv4_addr', $split($management, false)],
            'management6' => ['ipv6_addr', $split($management, true)],
            'routers4' => ['ipv4_addr', $split($routers, false)],
            'routers6' => ['ipv6_addr', $split($routers, true)],
        ];
        $body = [$table . ' {'];
        foreach ($sets as $name => [$type, $elements]) {
            if ($elements !== []) {
                $body[] = "    set $name {";
                $body[] = "        type $type; flags interval;";
                $body[] = '        elements = { ' . implode(', ', $elements) . ' }';
                $body[] = '    }';
            }
        }
        $ports = array_values(array_unique([...array_map('intval', $sshPorts ?: [22]), 80, 443]));
        foreach ($ports as $port) {
            if ($port < 1 || $port > 65535) {
                throw new RuntimeException('Bad SSH port.');
            }
        }
        $portList = '{ ' . implode(', ', $ports) . ' }';
        $chain = [
            'type filter hook input priority filter; policy drop;',
            'ct state established,related accept',
            'ct state invalid drop',
            'iif "lo" accept',
            'icmp type { destination-unreachable, time-exceeded, parameter-problem } accept',
            'icmp type echo-request limit rate 20/second accept',
            'icmpv6 type { destination-unreachable, packet-too-big, time-exceeded, parameter-problem, nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert } accept',
            'icmpv6 type echo-request limit rate 20/second accept',
            '# SSH and the portal from the management networks, before every rule: they cannot be locked out.',
        ];
        if ($sets['management4'][1] !== []) {
            $chain[] = "ip saddr @management4 tcp dport $portList accept";
        }
        if ($sets['management6'][1] !== []) {
            $chain[] = "ip6 saddr @management6 tcp dport $portList accept";
        }
        $chain[] = '# Rules (Settings → Firewall), in order.';
        foreach (array_slice((array) ($snapshot['rules'] ?? []), 0, self::MAX_RULES) as $rule) {
            array_push($chain, ...self::ruleLines((array) $rule, $sets['routers4'][1], $sets['routers6'][1], $now));
        }
        $body[] = '    chain input {';
        foreach ($chain as $line) {
            $body[] = '        ' . $line;
        }
        $body[] = '    }';
        $body[] = '}';

        return implode("\n", [...$head, ...$body]) . "\n";
    }

    // ------------------------------------------------------------------------- the portal's side

    private static function requestDir(): string
    {
        return self::$requestDir ?? Paths::data('firewall');
    }

    private static function statusDir(): string
    {
        return self::$statusDir ?? Paths::data('firewall-status');
    }

    /** Applying is set up on this server: the request folder exists and the web server may write in it. */
    public static function ready(): bool
    {
        return is_dir(self::requestDir()) && is_writable(self::requestDir());
    }

    /** @return array<string, mixed> radiusone-firewall's progress; a run that went silent counts as failed */
    public static function status(): array
    {
        $status = json_decode((string) @file_get_contents(self::statusDir() . '/status.json'), true);
        if (!is_array($status)) {
            return ['state' => 'none'];
        }
        if (in_array($status['state'] ?? '', ['applying', 'confirming'], true) && time() - (int) ($status['at'] ?? 0) > self::STALE_SECONDS) {
            return ['state' => 'failed', 'message' => 'The firewall helper stopped without finishing. Check: sudo systemctl status radiusone-firewall-apply'] + $status;
        }

        return $status;
    }

    /** @return array{ssh_ports:list<int>,ufw_active:bool,nft:bool} what radiusone-firewall detect found on this server */
    public static function host(): array
    {
        $host = json_decode((string) @file_get_contents(self::statusDir() . '/host.json'), true);

        return [
            'ssh_ports' => array_values(array_filter(array_map('intval', (array) ($host['ssh_ports'] ?? [22])))) ?: [22],
            'ufw_active' => (bool) ($host['ufw_active'] ?? false),
            'nft' => (bool) ($host['nft'] ?? true),
        ];
    }

    public static function busy(): bool
    {
        return is_file(self::requestDir() . '/request') || in_array(self::status()['state'] ?? '', ['applying', 'confirming'], true);
    }

    private static function assertCanApply(): void
    {
        if (!self::ready()) {
            throw new RuntimeException('The firewall is not set up on this server yet. Run sudo radiusone-update, or sudo bash ops/radiusone-postinstall in the app folder, once.');
        }
        if (!self::host()['nft']) {
            throw new RuntimeException('nftables is not installed on this server: run sudo apt install nftables.');
        }
        if (self::busy()) {
            throw new InvalidArgumentException('A firewall change is being applied. Wait for it to finish.');
        }
    }

    /** @param array{enabled:bool,management_networks:list<string>} $config */
    private static function assertReachable(string $clientIp, array $config): void
    {
        if ($config['management_networks'] === []) {
            throw new InvalidArgumentException('List at least one management network: SSH and the portal stay open to it.');
        }
        if (!self::allowedFrom($clientIp, $config['management_networks'])) {
            throw new InvalidArgumentException('Your address ' . $clientIp . ' is not in the management networks. Add it first, or you could lose the portal.');
        }
    }

    /** Turns the firewall on with the saved rules; the portal must confirm it can still reach the server. */
    public static function enable(string $by, string $clientIp): string
    {
        self::assertCanApply();
        $config = self::config();
        self::assertReachable($clientIp, $config);
        self::saveConfig(['enabled' => true, 'management_networks' => $config['management_networks']]);

        return self::request($by, true);
    }

    /** Removes the table. It cannot lock anyone out, so no confirmation is needed. Rules and networks are kept. */
    public static function disable(string $by): string
    {
        self::assertCanApply();
        $config = self::config();
        self::saveConfig(['enabled' => false, 'management_networks' => $config['management_networks']]);

        return self::request($by, false);
    }

    /** Puts the draft in force while the firewall is on. */
    public static function apply(string $by, string $clientIp): string
    {
        self::assertCanApply();
        $config = self::config();
        if (!$config['enabled']) {
            throw new InvalidArgumentException('The firewall is off: turn it on to put these rules in force.');
        }
        self::assertReachable($clientIp, $config);

        return self::request($by, true);
    }

    private static function request(string $by, bool $confirm): string
    {
        $snapshot = self::draft() + ['id' => bin2hex(random_bytes(8)), 'by' => $by, 'at' => date('Y-m-d H:i:s')];
        self::render($snapshot, self::host()['ssh_ports']);
        $old = Settings::value(self::ACTIVE, '');
        Settings::save(self::PREVIOUS, $old);
        Settings::save(self::ACTIVE, json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::writeRequest(['id' => $snapshot['id'], 'confirm' => $confirm, 'by' => $by]);

        return (string) $snapshot['id'];
    }

    /** @param array<string, mixed> $request */
    private static function writeRequest(array $request): void
    {
        $file = self::requestDir() . '/request';
        if (@file_put_contents($file . '.new', json_encode($request, JSON_THROW_ON_ERROR)) === false || !@rename($file . '.new', $file)) {
            throw new RuntimeException('The firewall request could not be written in ' . self::requestDir() . '.');
        }
    }

    /** Sent by the page of whoever applied, once it sees the rules in force: proof they can still reach the server. */
    public static function confirm(string $id, string $by): bool
    {
        $status = self::status();
        if (($status['state'] ?? '') !== 'confirming' || ($status['id'] ?? '') !== $id || ($status['by'] ?? '') !== $by) {
            return false;
        }
        $file = self::requestDir() . '/confirm';

        return @file_put_contents($file . '.new', $id) !== false && @rename($file . '.new', $file);
    }

    /**
     * Every minute (scheduler): the rules in force follow the routers and expired rules, without a new Apply. No
     * confirmation: the operator confirmed the rules, and this only changes who the RADIUS rule lets in.
     *
     * @return string what happened, for the log
     */
    public static function sync(): string
    {
        $active = self::active();
        if ($active === null || empty($active['config']['enabled']) || !self::ready() || self::busy()) {
            return 'nothing to do';
        }
        $routers = self::routers()['networks'];
        if (($active['routers'] ?? []) !== $routers) {
            $active['routers'] = $routers;
            Settings::save(self::ACTIVE, json_encode($active, JSON_THROW_ON_ERROR));
        }
        $status = self::status();
        $wanted = hash('sha256', self::render($active, self::host()['ssh_ports']));
        if (($status['hash'] ?? '') === $wanted || ($status['tried'] ?? '') === $wanted) {
            return 'in force';
        }
        self::writeRequest(['id' => $active['id'] ?? '', 'confirm' => false, 'by' => 'sync']);

        return 'loading the rules';
    }

    // ------------------------------------------------------------------------- radiusone-firewall (root)

    /** @param array<string, mixed> $values */
    private static function writeStatus(array $values): void
    {
        $dir = self::statusDir();
        $values['at'] = time();
        $tmp = $dir . '/status.json.new';
        file_put_contents($tmp, json_encode($values, JSON_THROW_ON_ERROR));
        @chmod($tmp, 0640);
        @chgrp($tmp, 'www-data');
        rename($tmp, $dir . '/status.json');
    }

    /** @return array{0:bool,1:string} */
    private static function nft(string $script, bool $check = false): array
    {
        $result = BoundedProcess::run([...self::$nft, ...($check ? ['-c'] : []), '-f', '-'], $script, 30);

        return [$result['exit'] === 0, trim($result['output'] . $result['error'])];
    }

    /** The table as it runs now, as a script that brings it back (or removes it). */
    private static function currentTable(): string
    {
        $result = BoundedProcess::run([...self::$nft, 'list', 'table', 'inet', self::TABLE], '', 30, 4194304);
        $head = 'table inet ' . self::TABLE . "\ndelete table inet " . self::TABLE . "\n";

        return $head . ($result['exit'] === 0 ? $result['output'] : '');
    }

    /**
     * Puts the previous snapshot back, unless someone applied again meanwhile.
     *
     * @param  array<string, mixed>|null  $previous
     */
    private static function restore(?array $previous, string $failedId): void
    {
        $active = self::active();
        if (($active['id'] ?? '') !== $failedId) {
            return;
        }
        Settings::save(self::ACTIVE, $previous === null ? '' : json_encode($previous, JSON_THROW_ON_ERROR));
        $config = self::config();
        self::saveConfig(['enabled' => (bool) ($previous['config']['enabled'] ?? false), 'management_networks' => $config['management_networks']]);
    }

    /** radiusone-firewall apply: what radiusone-firewall-apply.service runs. */
    public static function rootApply(): int
    {
        $requestFile = self::requestDir() . '/request';
        // The web server owns this folder: never follow a link it left, and use only id-free fields from the file.
        $request = is_file($requestFile) && !is_link($requestFile) ? json_decode((string) @file_get_contents($requestFile), true) : null;
        $request = is_array($request) ? $request : [];
        $by = mb_substr((string) ($request['by'] ?? ''), 0, 64);
        // Busy before the request disappears, so the portal never sees a moment when it may apply again.
        self::writeStatus(['state' => 'applying', 'id' => (string) ($request['id'] ?? ''), 'by' => $by, 'message' => 'Applying the rules…']);
        @unlink($requestFile);
        @unlink(self::requestDir() . '/confirm');

        $snapshot = self::active();
        $id = (string) ($snapshot['id'] ?? '');
        $previous = json_decode(Settings::value(self::PREVIOUS, ''), true);
        $previous = is_array($previous) ? $previous : null;
        $sshPorts = self::host()['ssh_ports'];
        try {
            $script = self::render($snapshot, $sshPorts);
        } catch (RuntimeException $e) {
            self::writeStatus(['state' => 'failed', 'id' => $id, 'by' => $by, 'message' => 'The rules could not be rendered: ' . $e->getMessage()]);

            return 1;
        }
        $hash = hash('sha256', $script);
        $fromSync = empty($request['confirm']) && $by === 'sync';
        [$ok, $out] = self::nft($script, true);
        if ($ok) {
            $before = self::currentTable();
            [$ok, $out] = self::nft($script);
        }
        if (!$ok) {
            // A sync keeps the rules that run now; an operator's change goes back in the portal too.
            if (!$fromSync) {
                self::restore($previous, $id);
            }
            self::writeStatus(['state' => 'failed', 'id' => $id, 'by' => $by, 'tried' => $hash, 'message' => 'nft refused the rules; nothing changed: ' . mb_substr($out, 0, 2000)]);

            return 1;
        }
        $enabled = !empty($snapshot['config']['enabled']);
        if (!empty($request['confirm'])) {
            $deadline = time() + self::$confirmSeconds;
            self::writeStatus(['state' => 'confirming', 'id' => $id, 'by' => $by, 'hash' => $hash, 'deadline' => $deadline, 'enabled' => $enabled,
                'message' => 'Applied. Waiting for the portal to confirm it can still reach the server (' . self::$confirmSeconds . ' seconds).']);
            $confirmFile = self::requestDir() . '/confirm';
            $confirmed = false;
            while (time() < $deadline) {
                if (is_file($confirmFile) && !is_link($confirmFile) && trim((string) @file_get_contents($confirmFile, false, null, 0, 64)) === $id) {
                    $confirmed = true;
                    break;
                }
                usleep(250000);
            }
            @unlink($confirmFile);
            if (!$confirmed) {
                self::nft($before);
                self::restore($previous, $id);
                self::writeStatus(['state' => 'rolled_back', 'id' => $id, 'by' => $by, 'enabled' => !empty($previous['config']['enabled']),
                    'hash' => $previous === null ? null : hash('sha256', self::render($previous, $sshPorts)),
                    'message' => 'The portal did not confirm in time, so the previous rules were put back.']);

                return 1;
            }
        }
        self::saveBootRules($script);
        self::writeStatus(['state' => $enabled ? 'applied' : 'disabled', 'id' => $id, 'by' => $by, 'hash' => $hash, 'enabled' => $enabled,
            'message' => $enabled ? 'The firewall is on and the rules are in force.' : 'The firewall is off.']);

        return 0;
    }

    private static function saveBootRules(string $script): void
    {
        $file = self::statusDir() . '/active.nft';
        file_put_contents($file . '.new', $script);
        @chmod($file . '.new', 0600);
        rename($file . '.new', $file);
    }

    /** radiusone-firewall off, from a shell: the portal shows the firewall off and sync leaves it off. */
    public static function rootOff(): int
    {
        self::nft(self::render(null));
        $config = self::config();
        self::saveConfig(['enabled' => false, 'management_networks' => $config['management_networks']]);
        $active = self::active();
        if ($active !== null) {
            $active['config']['enabled'] = false;
            Settings::save(self::ACTIVE, json_encode($active, JSON_THROW_ON_ERROR));
        }
        self::saveBootRules(self::render(null));
        self::writeStatus(['state' => 'disabled', 'id' => (string) ($active['id'] ?? ''), 'by' => 'shell', 'enabled' => false, 'message' => 'The firewall was turned off from the server\'s shell.']);

        return 0;
    }
}
