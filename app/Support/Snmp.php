<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Minimal read-only SNMP client (GET, GETNEXT, GETBULK) over UDP: v1, v2c and v3 with the
 * User-based Security Model (RFC 3414; HMAC-SHA-2 per RFC 7860; AES-128 per RFC 3826).
 * Pure PHP so no php-snmp or net-snmp install is needed. It never sends SET.
 */
final class Snmp
{
    private const GET = 0xA0;
    private const GETNEXT = 0xA1;
    private const RESPONSE = 0xA2;
    private const GETBULK = 0xA5;
    private const REPORT = 0xA8;
    /** Marker for noSuchObject / noSuchInstance / endOfMibView values. */
    public const MISSING = null;

    /** USM authentication protocols: hash algorithm and truncated MAC length. */
    public const AUTH_PROTOCOLS = [
        'md5' => ['md5', 12], 'sha1' => ['sha1', 12],
        'sha224' => ['sha224', 16], 'sha256' => ['sha256', 24], 'sha384' => ['sha384', 32], 'sha512' => ['sha512', 48],
    ];
    public const PRIV_PROTOCOLS = ['aes128', 'des'];
    private const REPORTS = [
        '1.3.6.1.6.3.15.1.1.1.0' => 'the device does not support this security level for the user',
        '1.3.6.1.6.3.15.1.1.2.0' => 'the request was outside the time window',
        '1.3.6.1.6.3.15.1.1.3.0' => 'unknown SNMPv3 user name',
        '1.3.6.1.6.3.15.1.1.4.0' => 'unknown engine ID',
        '1.3.6.1.6.3.15.1.1.5.0' => 'wrong digest: check the authentication protocol and password',
        '1.3.6.1.6.3.15.1.1.6.0' => 'decryption error: check the privacy protocol and password',
    ];

    /** Discovered authoritative engines (ID, boots, time) per host:port for this PHP process. */
    private static array $engines = [];
    private static array $keys = [];
    private static int $salt = 0;

    private int $requestId;
    private float $deadline;
    private array $usm;

    /**
     * @param  array{user?:string,auth?:string,auth_pass?:string,priv?:string,priv_pass?:string,context?:string}  $usm  v3 only
     */
    public function __construct(
        private string $host,
        private string $community,
        private string $version = '2c',
        private int $port = 161,
        private float $timeout = 1.5,
        private int $retries = 1,
        float $budget = 6.0,
        array $usm = [],
    ) {
        if (!in_array($version, ['1', '2c', '3'], true)) {
            throw new RuntimeException('Unsupported SNMP version.');
        }
        $this->usm = self::normalizeUsm($usm);
        if ($version === '3') {
            self::validateUsm($this->usm);
        }
        $this->requestId = random_int(1, 0x3FFFFFFF);
        $this->deadline = microtime(true) + $budget;
        if (self::$salt === 0) {
            self::$salt = random_int(1, PHP_INT_MAX >> 1);
        }
    }

    public static function normalizeUsm(array $usm): array
    {
        return [
            'user' => (string) ($usm['user'] ?? ''),
            'auth' => strtolower((string) (($usm['auth'] ?? '') ?: 'none')),
            'auth_pass' => (string) ($usm['auth_pass'] ?? ''),
            'priv' => strtolower((string) (($usm['priv'] ?? '') ?: 'none')),
            'priv_pass' => (string) ($usm['priv_pass'] ?? ''),
            'context' => (string) ($usm['context'] ?? ''),
        ];
    }

    /** Throws a readable error for settings the protocol or this server cannot use. */
    public static function validateUsm(array $usm): void
    {
        $usm = self::normalizeUsm($usm);
        if ($usm['user'] === '' || strlen($usm['user']) > 32) {
            throw new RuntimeException('SNMPv3 user name is required (up to 32 characters).');
        }
        if ($usm['auth'] !== 'none' && !isset(self::AUTH_PROTOCOLS[$usm['auth']])) {
            throw new RuntimeException('Unsupported SNMPv3 authentication protocol.');
        }
        if ($usm['priv'] !== 'none' && !in_array($usm['priv'], self::PRIV_PROTOCOLS, true)) {
            throw new RuntimeException('Unsupported SNMPv3 privacy protocol.');
        }
        if ($usm['priv'] !== 'none' && $usm['auth'] === 'none') {
            throw new RuntimeException('SNMPv3 privacy (encryption) requires authentication.');
        }
        if ($usm['auth'] !== 'none' && strlen($usm['auth_pass']) < 8) {
            throw new RuntimeException('SNMPv3 authentication password must be at least 8 characters.');
        }
        if ($usm['priv'] !== 'none' && strlen($usm['priv_pass']) < 8) {
            throw new RuntimeException('SNMPv3 privacy password must be at least 8 characters.');
        }
        if ($usm['priv'] === 'des' && !self::desAvailable()) {
            throw new RuntimeException('DES is disabled in this server\'s OpenSSL; configure the router user with AES.');
        }
    }

    public static function desAvailable(): bool
    {
        return in_array('des-cbc', openssl_get_cipher_methods(), true);
    }

    /** @return array<string, mixed> OID => value (null when the device does not have it). */
    public function get(array $oids): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($oids)), 20) as $chunk) {
            $result += $this->getChunk($chunk);
        }
        return $result;
    }

    /** @return array<string, mixed> every OID below $root, in device order. */
    public function walk(string $root, int $limit = 2000): array
    {
        $root = trim($root, '.');
        $rows = [];
        $cursor = $root;
        while (count($rows) < $limit) {
            $binds = $this->version === '1'
                ? $this->request(self::GETNEXT, [$cursor])
                : $this->request(self::GETBULK, [$cursor], 0, min(40, $limit - count($rows)));
            if (!$binds) {
                break;
            }
            foreach ($binds as [$oid, $value, $end]) {
                if ($end || !str_starts_with($oid . '.', $root . '.') || $oid === $root || self::compare($oid, $cursor) <= 0) {
                    return $rows;
                }
                $rows[$oid] = $value;
                $cursor = $oid;
            }
        }
        return $rows;
    }

    private function getChunk(array $oids): array
    {
        try {
            $binds = $this->request(self::GET, $oids);
        } catch (SnmpError $e) {
            // v1 fails the whole PDU on one unknown OID; v2c/v3 can answer tooBig. Retry one by one.
            if (count($oids) === 1) {
                return [$oids[0] => null];
            }
            $result = [];
            foreach ($oids as $oid) {
                $result += $this->getChunk([$oid]);
            }
            return $result;
        }
        $result = array_fill_keys($oids, null);
        foreach ($binds as [$oid, $value]) {
            if (array_key_exists($oid, $result)) {
                $result[$oid] = $value;
            }
        }
        return $result;
    }

    /** @return list<array{0:string,1:mixed,2:bool}> */
    private function request(int $type, array $oids, int $nonRepeaters = 0, int $maxRepetitions = 0): array
    {
        if ($this->version === '3' && !$this->engineKnown()) {
            $id = $this->nextId();
            $this->exchange($this->discoveryPacket($id), fn ($data) => ($r = $this->decodeMessage($data, $id)) && $this->engineKnown() ? $r : null);
        }
        for ($attempt = 0; ; $attempt++) {
            $id = $this->nextId();
            $reply = $this->exchange($this->encodeMessage($type, $oids, $id, $nonRepeaters, $maxRepetitions), fn ($data) => $this->decodeMessage($data, $id));
            if (isset($reply['report'])) {
                // An authenticated notInTimeWindows report carries the agent's clock; retry once with it.
                if ($attempt === 0 && $reply['report'] === '1.3.6.1.6.3.15.1.1.2.0' && $reply['authentic']) {
                    continue;
                }
                throw new RuntimeException('SNMPv3: ' . self::reportMessage($reply['report']) . '.');
            }
            if ($reply['error'] !== 0) {
                throw new SnmpError('SNMP error status ' . $reply['error']);
            }
            return $reply['binds'];
        }
    }

    /** Sends a packet (with retries) and returns the first reply the decoder accepts. */
    private function exchange(string $packet, callable $decode): array
    {
        $socket = @stream_socket_client('udp://' . self::address($this->host, $this->port), $errno, $error, $this->timeout);
        if (!$socket) {
            throw new RuntimeException('Cannot open UDP socket to ' . $this->host . ': ' . $error);
        }
        try {
            for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
                $remaining = $this->deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new RuntimeException('SNMP poll took too long; partial data only.');
                }
                if (@fwrite($socket, $packet) === false) {
                    throw new RuntimeException('Cannot send SNMP request.');
                }
                $until = microtime(true) + min($this->timeout, $remaining);
                while (($wait = $until - microtime(true)) > 0) {
                    $read = [$socket];
                    $none = null;
                    if (!@stream_select($read, $none, $none, (int) $wait, (int) (fmod($wait, 1) * 1e6))) {
                        break;
                    }
                    $data = @fread($socket, 65535);
                    if ($data === false || $data === '') {
                        break;
                    }
                    $reply = $decode($data);
                    if ($reply !== null) {
                        return $reply;
                    } // Otherwise a late answer to an earlier retry, or forged.
                }
            }
        } finally {
            fclose($socket);
        }
        $hint = $this->version === '3' ? 'check user, security level, protocols and ACL' : 'check community, version and ACL';
        throw new RuntimeException('No SNMP response from ' . $this->host . ':' . $this->port . ' (' . $hint . ').');
    }

    /** @internal */
    public function nextId(): int
    {
        return $this->requestId = ($this->requestId % 0x3FFFFFFF) + 1;
    }

    /** @internal */
    public function engineKnown(): bool
    {
        return $this->version !== '3' || isset(self::$engines[$this->engineKey()]);
    }

    private function engineKey(): string
    {
        return self::address($this->host, $this->port);
    }

    /** @internal Engine discovery: unauthenticated, reportable, empty user and engine ID. */
    public function discoveryPacket(int $id): string
    {
        return $this->v3Message(self::pdu(self::GET, $id, 0, 0, []), $id, false);
    }

    /** @internal */
    public function encodeMessage(int $type, array $oids, int $id, int $nonRepeaters = 0, int $maxRepetitions = 0): string
    {
        $pdu = self::pdu($type, $id, $type === self::GETBULK ? $nonRepeaters : 0, $type === self::GETBULK ? $maxRepetitions : 0, $oids);
        if ($this->version !== '3') {
            return self::tlv(0x30, self::encodeInt($this->version === '1' ? 0 : 1) . self::tlv(0x04, $this->community) . $pdu);
        }
        return $this->v3Message($pdu, $id, true);
    }

    private static function pdu(int $type, int $id, int $second, int $third, array $oids): string
    {
        $varbinds = '';
        foreach ($oids as $oid) {
            $varbinds .= self::tlv(0x30, self::encodeOid($oid) . "\x05\x00");
        }
        return self::tlv($type, self::encodeInt($id) . self::encodeInt($second) . self::encodeInt($third) . self::tlv(0x30, $varbinds));
    }

    private function v3Message(string $pdu, int $msgId, bool $secure): string
    {
        $engine = $secure ? $this->engine() : ['id' => '', 'boots' => 0, 'time' => 0];
        $auth = $secure && $this->usm['auth'] !== 'none';
        $priv = $auth && $this->usm['priv'] !== 'none';
        $scoped = self::tlv(0x30, self::tlv(0x04, $engine['id']) . self::tlv(0x04, $secure ? $this->usm['context'] : '') . $pdu);
        $privParams = '';
        $msgData = $scoped;
        if ($priv) {
            [$cipher, $privParams] = $this->encrypt($scoped, $engine);
            $msgData = self::tlv(0x04, $cipher);
        }
        $authLength = $auth ? self::AUTH_PROTOCOLS[$this->usm['auth']][1] : 0;
        $usmPrefix = self::tlv(0x04, $engine['id']) . self::encodeInt($engine['boots']) . self::encodeInt($engine['time']) . self::tlv(0x04, $secure ? $this->usm['user'] : '');
        $usmBody = $usmPrefix . self::tlv(0x04, str_repeat("\x00", $authLength)) . self::tlv(0x04, $privParams);
        $usmSeq = self::tlv(0x30, $usmBody);
        $flags = ($auth ? 1 : 0) | ($priv ? 2 : 0) | 4;
        $head = self::encodeInt(3) . self::tlv(0x30, self::encodeInt($msgId) . self::encodeInt(65507) . self::tlv(0x04, chr($flags)) . self::encodeInt(3));
        $body = $head . self::tlv(0x04, $usmSeq) . $msgData;
        $message = self::tlv(0x30, $body);
        if ($auth) {
            // The MAC covers the whole message with authParameters zeroed, then fills that field.
            $position = self::headerLength(strlen($body)) + strlen($head) + self::headerLength(strlen($usmSeq)) + self::headerLength(strlen($usmBody)) + strlen($usmPrefix) + 2;
            $message = substr_replace($message, $this->mac($message, $engine['id']), $position, $authLength);
        }
        return $message;
    }

    /** Current estimate of the agent's engine clock. */
    private function engine(): array
    {
        $engine = self::$engines[$this->engineKey()] ?? throw new RuntimeException('SNMPv3 engine not discovered.');
        return ['id' => $engine['id'], 'boots' => $engine['boots'], 'time' => $engine['time'] + (int) (microtime(true) - $engine['at'])];
    }

    private function mac(string $message, string $engineId): string
    {
        [$algorithm, $length] = self::AUTH_PROTOCOLS[$this->usm['auth']];
        return substr(hash_hmac($algorithm, $message, self::localizeKey($algorithm, $this->usm['auth_pass'], $engineId), true), 0, $length);
    }

    private function privKey(string $engineId): string
    {
        // RFC 3414/3826: the privacy key is localized with the authentication hash.
        return self::localizeKey(self::AUTH_PROTOCOLS[$this->usm['auth']][0], $this->usm['priv_pass'], $engineId);
    }

    /** @return array{0:string,1:string} ciphertext and privParameters (salt) */
    private function encrypt(string $plain, array $engine): array
    {
        $key = $this->privKey($engine['id']);
        self::$salt = (self::$salt % (PHP_INT_MAX - 1)) + 1;
        if ($this->usm['priv'] === 'aes128') {
            $salt = pack('J', self::$salt);
            $cipher = openssl_encrypt($plain, 'aes-128-cfb', substr($key, 0, 16), OPENSSL_RAW_DATA, pack('NN', $engine['boots'], $engine['time']) . $salt);
        } else {
            $salt = pack('NN', $engine['boots'], self::$salt & 0xFFFFFFFF);
            $padded = $plain . str_repeat("\x00", (8 - strlen($plain) % 8) % 8);
            $cipher = openssl_encrypt($padded, 'des-cbc', substr($key, 0, 8), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 8, 8) ^ $salt);
        }
        if ($cipher === false) {
            throw new RuntimeException('SNMPv3 encryption failed.');
        }
        return [$cipher, $salt];
    }

    private function decrypt(string $cipher, string $salt, int $boots, int $time, string $engineId): ?string
    {
        if (strlen($salt) !== 8) {
            return null;
        }
        $key = $this->privKey($engineId);
        $plain = $this->usm['priv'] === 'aes128'
            ? openssl_decrypt($cipher, 'aes-128-cfb', substr($key, 0, 16), OPENSSL_RAW_DATA, pack('NN', $boots, $time) . $salt)
            : (strlen($cipher) % 8 === 0 ? openssl_decrypt($cipher, 'des-cbc', substr($key, 0, 8), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 8, 8) ^ $salt) : false);
        return $plain === false ? null : $plain;
    }

    /**
     * @internal Decodes a reply to request $id. Returns null for unrelated, malformed or unauthentic packets.
     *
     * @return array{id:int,error:int,binds:list<array>}|array{report:string,authentic:bool}|null
     */
    public function decodeMessage(string $data, int $id): ?array
    {
        if ($this->version !== '3') {
            $reply = self::parseResponse($data);
            return $reply !== null && $reply['id'] === $id ? $reply : null;
        }
        try {
            $o = 0;
            [$tag, $message, $base] = self::readTlv($data, $o);
            $m = 0;
            if ($tag !== 0x30 || self::decodeInt(self::readTlv($message, $m)[1]) !== 3) {
                return null;
            }
            [, $global] = self::readTlv($message, $m);
            $g = 0;
            $msgId = self::decodeInt(self::readTlv($global, $g)[1]);
            self::readTlv($global, $g);
            $flags = ord(self::readTlv($global, $g)[1] ?: "\x00");
            if ($msgId !== $id) {
                return null;
            }
            [, $usmRaw, $usmOffset] = self::readTlv($message, $m);
            $u = 0;
            [, $usm, $usmSeqOffset] = self::readTlv($usmRaw, $u);
            $s = 0;
            $engineId = self::readTlv($usm, $s)[1];
            $boots = self::decodeInt(self::readTlv($usm, $s)[1]);
            $time = self::decodeInt(self::readTlv($usm, $s)[1]);
            self::readTlv($usm, $s); // user name
            [, $authParams, $authOffset] = self::readTlv($usm, $s);
            $privParams = self::readTlv($usm, $s)[1];
            [$dataTag, $msgData] = self::readTlv($message, $m);

            $authentic = false;
            if ($flags & 1) {
                if ($this->usm['auth'] === 'none' || strlen($authParams) !== self::AUTH_PROTOCOLS[$this->usm['auth']][1]) {
                    return null;
                }
                $position = $base + $usmOffset + $usmSeqOffset + $authOffset;
                $zeroed = substr_replace($data, str_repeat("\x00", strlen($authParams)), $position, strlen($authParams));
                if (!hash_equals($this->mac($zeroed, $engineId), $authParams)) {
                    return null;
                } // Forged or wrong key: drop.
                $authentic = true;
            }
            if ($flags & 2) {
                if (!$authentic || $dataTag !== 0x04 || $this->usm['priv'] === 'none') {
                    return null;
                }
                $scoped = $this->decrypt($msgData, $privParams, $boots, $time, $engineId);
                if ($scoped === null) {
                    return null;
                }
                $c = 0;
                [, $scopedBody] = self::readTlv($scoped, $c); // Trailing DES padding is ignored.
            } elseif ($dataTag === 0x30) {
                $scopedBody = $msgData;
            } else {
                return null;
            }
            // Only authenticated messages may move the clock; an unauthenticated report may only seed discovery.
            $key = $this->engineKey();
            if ($engineId !== '' && ($authentic || !isset(self::$engines[$key]))) {
                self::$engines[$key] = ['id' => $engineId, 'boots' => $boots, 'time' => $time, 'at' => microtime(true)];
            }
            $p = 0;
            self::readTlv($scopedBody, $p); // contextEngineID
            self::readTlv($scopedBody, $p); // contextName
            [$pduTag, $pdu] = self::readTlv($scopedBody, $p);
            $reply = self::parsePdu($pdu);
            if ($pduTag === self::REPORT) {
                return ['report' => (string) ($reply['binds'][0][0] ?? ''), 'authentic' => $authentic];
            }
            if ($pduTag !== self::RESPONSE || $reply['id'] !== $id) {
                return null;
            }
            if ($this->usm['auth'] !== 'none' && !$authentic) {
                return null;
            } // Downgraded reply.
            return $reply;
        } catch (RuntimeException) {
            return null;
        }
    }

    public static function reportMessage(string $oid): string
    {
        return self::REPORTS[$oid] ?? 'report ' . ($oid !== '' ? $oid : 'without details');
    }

    /** RFC 3414 A.2 password-to-key followed by localization to the engine ID. */
    public static function localizeKey(string $algorithm, string $password, string $engineId): string
    {
        $cache = $algorithm . "\x00" . hash('sha256', $password) . "\x00" . $engineId;
        if (!isset(self::$keys[$cache])) {
            if ($password === '') {
                throw new RuntimeException('SNMPv3 password missing.');
            }
            $expanded = substr(str_repeat($password, intdiv(1048576, strlen($password)) + 1), 0, 1048576);
            $ku = hash($algorithm, $expanded, true);
            self::$keys[$cache] = hash($algorithm, $ku . $engineId . $ku, true);
        }
        return self::$keys[$cache];
    }

    private static function address(string $host, int $port): string
    {
        return (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
    }

    /**
     * One GET to many devices at once, so a page listing every NAS waits for the slowest device
     * once instead of the sum of all timeouts. SNMPv3 targets discover their engine first.
     * Unanswered requests are resent once at half time.
     *
     * @param  array<string, array{host:string,port:int,community:string,version:string,usm?:array}>  $targets
     * @return array<string, array{ok:bool,latency_ms:?int,values:array<string,mixed>,error:?string}>
     */
    public static function probe(array $targets, array $oids, float $timeout = 1.5): array
    {
        $results = [];
        $pending = [];
        $started = microtime(true);
        $send = static function (array &$state) use ($oids): void {
            $state['id'] = $state['client']->nextId();
            $state['packet'] = $state['phase'] === 'discover' ? $state['client']->discoveryPacket($state['id']) : $state['client']->encodeMessage(self::GET, $oids, $state['id']);
            $state['sent'] = microtime(true);
            $state['resent'] = false;
            @fwrite($state['socket'], $state['packet']);
        };
        foreach ($targets as $key => $target) {
            $results[$key] = ['ok' => false, 'latency_ms' => null, 'values' => [], 'error' => 'No SNMP response'];
            try {
                $client = new self((string) $target['host'], (string) ($target['community'] ?? ''), (string) $target['version'], (int) $target['port'], $timeout, 0, $timeout + 1, $target['usm'] ?? []);
                $socket = @stream_socket_client('udp://' . self::address((string) $target['host'], (int) $target['port']), $errno, $error, $timeout);
                if (!$socket) {
                    $results[$key]['error'] = 'Cannot send to ' . $target['host'];
                    continue;
                }
                $pending[$key] = ['client' => $client, 'socket' => $socket, 'phase' => $client->engineKnown() ? 'get' : 'discover', 'time_retry' => false];
                $send($pending[$key]);
            } catch (RuntimeException $e) {
                $results[$key]['error'] = $e->getMessage();
            }
        }
        while ($pending && ($left = $started + $timeout - microtime(true)) > 0) {
            foreach ($pending as &$state) {
                if (!$state['resent'] && microtime(true) - $state['sent'] >= $timeout / 2) {
                    @fwrite($state['socket'], $state['packet']);
                    $state['resent'] = true;
                }
            }
            unset($state);
            $read = array_column($pending, 'socket');
            $none = null;
            $wait = min($left, $timeout / 4);
            if (!@stream_select($read, $none, $none, (int) $wait, (int) (fmod($wait, 1) * 1e6))) {
                continue;
            }
            foreach (array_keys($pending) as $key) {
                $state = &$pending[$key];
                if (!in_array($state['socket'], $read, true)) {
                    unset($state);
                    continue;
                }
                $reply = $state['client']->decodeMessage((string) @fread($state['socket'], 65535), $state['id']);
                if ($reply === null) {
                    unset($state);
                    continue;
                }
                if ($state['phase'] === 'discover') {
                    if ($state['client']->engineKnown()) {
                        $state['phase'] = 'get';
                        $send($state);
                    }
                    unset($state);
                    continue;
                }
                if (isset($reply['report']) && $reply['report'] === '1.3.6.1.6.3.15.1.1.2.0' && $reply['authentic'] && !$state['time_retry']) {
                    $state['time_retry'] = true;
                    $send($state);
                    unset($state);
                    continue;
                }
                if (isset($reply['report'])) {
                    $results[$key]['error'] = 'SNMPv3: ' . self::reportMessage($reply['report']);
                } else {
                    $values = array_fill_keys($oids, null);
                    foreach ($reply['binds'] as [$oid, $value]) {
                        if (array_key_exists($oid, $values)) {
                            $values[$oid] = $value;
                        }
                    }
                    $results[$key] = ['ok' => $reply['error'] === 0, 'latency_ms' => (int) round((microtime(true) - $state['sent']) * 1000), 'values' => $values,
                        'error' => $reply['error'] === 0 ? null : 'SNMP error status ' . $reply['error']];
                }
                fclose($state['socket']);
                unset($state, $pending[$key]);
            }
        }
        foreach ($pending as $state) {
            fclose($state['socket']);
        }
        return $results;
    }

    private static function parseResponse(string $data): ?array
    {
        try {
            $offset = 0;
            [$tag, $message] = self::readTlv($data, $offset);
            if ($tag !== 0x30) {
                return null;
            }
            $o = 0;
            self::readTlv($message, $o); // version
            self::readTlv($message, $o); // community
            [$pduTag, $pdu] = self::readTlv($message, $o);
            return $pduTag === self::RESPONSE ? self::parsePdu($pdu) : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    /** @return array{id:int,error:int,binds:list<array{0:string,1:mixed,2:bool}>} */
    private static function parsePdu(string $pdu): array
    {
        $p = 0;
        $id = self::decodeInt(self::readTlv($pdu, $p)[1]);
        $error = self::decodeInt(self::readTlv($pdu, $p)[1]);
        self::readTlv($pdu, $p); // error index
        [, $list] = self::readTlv($pdu, $p);
        $binds = [];
        $l = 0;
        while ($l < strlen($list)) {
            [, $bind] = self::readTlv($list, $l);
            $b = 0;
            $oid = self::decodeOid(self::readTlv($bind, $b)[1]);
            [$valueTag, $raw] = self::readTlv($bind, $b);
            $binds[] = [$oid, self::decodeValue($valueTag, $raw), $valueTag === 0x82];
        }
        return ['id' => $id, 'error' => $error, 'binds' => $binds];
    }

    private static function decodeValue(int $tag, string $raw): mixed
    {
        return match ($tag) {
            0x02 => self::decodeInt($raw),
            0x04, 0x44 => $raw,
            0x06 => self::decodeOid($raw),
            0x40 => strlen($raw) === 4 ? implode('.', array_map('ord', str_split($raw))) : bin2hex($raw),
            0x41, 0x42, 0x43, 0x46 => self::decodeUnsigned($raw),
            default => null, // NULL, noSuchObject, noSuchInstance, endOfMibView
        };
    }

    /** @return array{0:int,1:string,2:int} tag, value and the value's offset in $data */
    public static function readTlv(string $data, int &$offset): array
    {
        if ($offset + 2 > strlen($data)) {
            throw new RuntimeException('Truncated BER.');
        }
        $tag = ord($data[$offset++]);
        $length = ord($data[$offset++]);
        if ($length & 0x80) {
            $bytes = $length & 0x7F;
            if ($bytes < 1 || $bytes > 4 || $offset + $bytes > strlen($data)) {
                throw new RuntimeException('Bad BER length.');
            }
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | ord($data[$offset++]);
            }
        }
        if ($offset + $length > strlen($data)) {
            throw new RuntimeException('Truncated BER value.');
        }
        $start = $offset;
        $value = substr($data, $offset, $length);
        $offset += $length;
        return [$tag, $value, $start];
    }

    public static function tlv(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $value;
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $value;
    }

    private static function headerLength(int $length): int
    {
        return $length < 0x80 ? 2 : 2 + strlen(ltrim(pack('N', $length), "\x00"));
    }

    public static function encodeInt(int $value): string
    {
        $bytes = ltrim(pack('J', $value), "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return self::tlv(0x02, $bytes);
    }

    public static function decodeInt(string $raw): int
    {
        if ($raw === '') {
            return 0;
        }
        $value = (ord($raw[0]) & 0x80) ? -1 : 0;
        foreach (str_split($raw) as $byte) {
            $value = ($value << 8) | ord($byte);
        }
        return $value;
    }

    /** Counter64 can exceed PHP_INT_MAX; it is returned as float then. */
    private static function decodeUnsigned(string $raw): int|float
    {
        $value = 0;
        foreach (str_split($raw) as $byte) {
            $value = is_int($value) && $value <= (PHP_INT_MAX >> 8) ? ($value << 8) | ord($byte) : $value * 256 + ord($byte);
        }
        return $value;
    }

    public static function encodeOid(string $oid): string
    {
        $arcs = array_map('intval', explode('.', trim($oid, '.')));
        if (count($arcs) < 2 || !preg_match('/^\d+(\.\d+)+$/', trim($oid, '.'))) {
            throw new RuntimeException('Invalid OID ' . $oid);
        }
        $body = chr(40 * $arcs[0] + $arcs[1]);
        foreach (array_slice($arcs, 2) as $arc) {
            $chunk = chr($arc & 0x7F);
            while ($arc >>= 7) {
                $chunk = chr(0x80 | ($arc & 0x7F)) . $chunk;
            }
            $body .= $chunk;
        }
        return self::tlv(0x06, $body);
    }

    public static function decodeOid(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $first = ord($raw[0]);
        $arcs = [intdiv(min($first, 80), 40), $first - 40 * intdiv(min($first, 80), 40)];
        $value = 0;
        for ($i = 1, $n = strlen($raw); $i < $n; $i++) {
            $byte = ord($raw[$i]);
            $value = ($value << 7) | ($byte & 0x7F);
            if (!($byte & 0x80)) {
                $arcs[] = $value;
                $value = 0;
            }
        }
        return implode('.', $arcs);
    }

    public static function compare(string $a, string $b): int
    {
        $x = explode('.', $a);
        $y = explode('.', $b);
        for ($i = 0, $n = min(count($x), count($y)); $i < $n; $i++) {
            if ((int) $x[$i] !== (int) $y[$i]) {
                return (int) $x[$i] <=> (int) $y[$i];
            }
        }
        return count($x) <=> count($y);
    }

    /** Encodes [oid, tag, value] varbinds as a PDU body; used by the loopback test agent. */
    public static function encodePdu(int $type, int $requestId, array $binds, int $error = 0): string
    {
        $list = '';
        foreach ($binds as [$oid, $tag, $value]) {
            $encoded = match ($tag) {
                0x02 => substr(self::encodeInt((int) $value), 2),
                0x06 => substr(self::encodeOid((string) $value), 2),
                0x40 => implode('', array_map('chr', array_map('intval', explode('.', (string) $value)))),
                0x41, 0x42, 0x43, 0x46 => substr(self::encodeInt((int) $value), 2),
                0x05, 0x80, 0x81, 0x82 => '',
                default => (string) $value,
            };
            $list .= self::tlv(0x30, self::encodeOid($oid) . self::tlv($tag, $encoded));
        }
        return self::tlv($type, self::encodeInt($requestId) . self::encodeInt($error) . self::encodeInt(0) . self::tlv(0x30, $list));
    }

    /** Builds a v1/v2c response packet; used only by the loopback test agent. */
    public static function encodeResponse(string $community, int $version, int $requestId, array $binds, int $error = 0): string
    {
        return self::tlv(0x30, self::encodeInt($version) . self::tlv(0x04, $community) . self::encodePdu(self::RESPONSE, $requestId, $binds, $error));
    }

    /** Parses a v1/v2c request packet or a bare PDU; used only by the loopback test agent. */
    public static function decodeRequest(string $data): ?array
    {
        try {
            $o = 0;
            [, $message] = self::readTlv($data, $o);
            $m = 0;
            $version = self::decodeInt(self::readTlv($message, $m)[1]);
            $community = self::readTlv($message, $m)[1];
            [$type, $pdu] = self::readTlv($message, $m);
            return ['version' => $version, 'community' => $community, 'type' => $type] + self::decodePduRequest($pdu);
        } catch (RuntimeException) {
            return null;
        }
    }

    /** @internal test agent helper */
    public static function decodePduRequest(string $pdu): array
    {
        $p = 0;
        $id = self::decodeInt(self::readTlv($pdu, $p)[1]);
        $second = self::decodeInt(self::readTlv($pdu, $p)[1]);
        $third = self::decodeInt(self::readTlv($pdu, $p)[1]);
        [, $list] = self::readTlv($pdu, $p);
        $oids = [];
        $l = 0;
        while ($l < strlen($list)) {
            [, $bind] = self::readTlv($list, $l);
            $b = 0;
            $oids[] = self::decodeOid(self::readTlv($bind, $b)[1]);
        }
        return ['id' => $id, 'non_repeaters' => $second, 'max_repetitions' => $third, 'oids' => $oids];
    }
}

final class SnmpError extends RuntimeException {}
