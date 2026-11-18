<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Secrets;
use RuntimeException;
use Throwable;

/**
 * The OLT's live console: a telnet session that stays open while an operator works in it, as with PuTTY.
 *
 * One long-running process (radiusone:olt-console, the radiusone-olt-console service) holds the sessions and listens
 * on a Unix socket only the web user can reach. The portal passes each operator's keystrokes in and the OLT's output
 * out (OltController::console*), so everything reaches the browser through the portal's own pages: no other port,
 * and nothing to change in the web server or a proxy in front of it. The OLT's login happens here: the browser never
 * sees the telnet user or password.
 */
final class OltConsole
{
    public const MAX_SESSIONS = 10;
    /** An MA5800 serves a handful of telnet logins, and the portal's own reads need one too. */
    public const PER_OLT = 2;
    private const IDLE_SECONDS = 600;
    private const MAX_SECONDS = 7200;
    private const LOGIN_SECONDS = 20;
    private const KEEP_BYTES = 1048576;
    private const ANSWER_BYTES = 262144;

    private const IAC = 255;
    private const DONT = 254;
    private const DO_ = 253;
    private const WONT = 252;
    private const WILL = 251;
    private const SB = 250;
    private const SE = 240;
    private const ECHO_ = 1;
    private const SGA = 3;
    private const NAWS = 31;

    /** @var resource|null */
    private $server;
    /** @var array<string, array<string, mixed>> */
    private array $sessions = [];
    private bool $stop = false;

    public static function socketPath(): string
    {
        $path = getenv('RADIUSONE_CONSOLE_SOCKET');
        return is_string($path) && $path !== '' ? $path : '/run/radiusone-console/console.sock';
    }

    /**
     * The portal's side: one request to the console service, and its answer.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function request(array $message): array
    {
        $socket = @stream_socket_client('unix://' . self::socketPath(), $errno, $error, 2.0);
        if (!$socket) {
            throw new OltConsoleUnavailable('The console service is not running on this server.');
        }
        try {
            stream_set_timeout($socket, 5);
            fwrite($socket, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
            $line = stream_get_line($socket, 4 * self::ANSWER_BYTES, "\n");
        } finally {
            fclose($socket);
        }
        $answer = is_string($line) ? json_decode($line, true) : null;
        if (!is_array($answer)) {
            throw new RuntimeException('The console service did not answer.');
        }
        if (($answer['ok'] ?? false) !== true) {
            throw new RuntimeException(is_string($answer['error'] ?? null) ? $answer['error'] : 'The console service refused.');
        }
        return $answer;
    }

    /** The service's side: serves until stopped. */
    public function serve(): void
    {
        $path = self::socketPath();
        if (file_exists($path)) {
            unlink($path);
        }
        $old = umask(0007);
        $server = @stream_socket_server('unix://' . $path, $errno, $error);
        umask($old);
        if (!$server) {
            throw new RuntimeException('Cannot listen on ' . $path . ': ' . $error);
        }
        $this->server = $server;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }
        while (!$this->stop) {
            $read = [$server];
            foreach ($this->sessions as $s) {
                if (is_resource($s['socket'])) {
                    $read[] = $s['socket'];
                }
            }
            $none = null;
            $ready = @stream_select($read, $none, $none, 0, 250000);
            if ($ready) {
                foreach ($read as $socket) {
                    if ($socket === $server) {
                        $this->serveOne();
                        continue;
                    }
                    foreach ($this->sessions as $id => $s) {
                        if ($s['socket'] === $socket) {
                            $this->receive($id);
                        }
                    }
                }
            }
            $this->expire();
        }
        foreach (array_keys($this->sessions) as $id) {
            $this->end($id, 'The console service stopped.');
        }
        fclose($server);
        @unlink($path);
    }

    /** One request from the portal: read its line, answer it, close. */
    private function serveOne(): void
    {
        $client = @stream_socket_accept($this->server, 1.0);
        if (!$client) {
            return;
        }
        try {
            stream_set_timeout($client, 2);
            $line = stream_get_line($client, 1048576, "\n");
            $message = is_string($line) ? json_decode($line, true) : null;
            try {
                $answer = is_array($message) ? $this->handle($message) : ['ok' => false, 'error' => 'Not a request.'];
            } catch (Throwable $e) {
                $answer = ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The console service failed: ' . get_class($e)];
            }
            @fwrite($client, json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        } finally {
            fclose($client);
        }
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function handle(array $m): array
    {
        $op = (string) ($m['op'] ?? '');
        if ($op === 'open') {
            return $this->open((int) ($m['olt'] ?? 0), (int) ($m['user'] ?? 0), (int) ($m['cols'] ?? 80), (int) ($m['rows'] ?? 24));
        }
        if ($op === 'ping') {
            return ['ok' => true, 'sessions' => count($this->sessions)];
        }
        $id = (string) ($m['session'] ?? '');
        $session = $this->sessions[$id] ?? null;
        if ($session === null || $session['user'] !== (int) ($m['user'] ?? -1) || $session['olt'] !== (int) ($m['olt'] ?? -1)) {
            return ['ok' => false, 'error' => 'This console session has ended. Open a new one.', 'ended' => true];
        }
        if ($op === 'close') {
            $this->end($id, '');
            unset($this->sessions[$id]);
            return ['ok' => true];
        }
        if ($op !== 'io') {
            return ['ok' => false, 'error' => 'Unknown request.'];
        }
        $this->sessions[$id]['touched'] = time();
        $this->resize($id, (int) ($m['cols'] ?? 0), (int) ($m['rows'] ?? 0));
        $lines = [];
        $input = base64_decode((string) ($m['input'] ?? ''), true);
        if (is_string($input) && $input !== '' && $this->sessions[$id]['state'] === 'ready') {
            $lines = $this->typed($id, $input);
            // An IAC byte typed is sent as two; Enter goes as CR LF, as PuTTY sends it.
            $this->write($id, str_replace(["\r\n", "\r", "\xff"], ["\r", "\r\n", "\xff\xff"], $input));
        }
        $s = $this->sessions[$id];
        $from = max((int) ($m['offset'] ?? 0), $s['base']);
        $data = substr($s['out'], $from - $s['base'], self::ANSWER_BYTES);
        $answer = ['ok' => true, 'state' => $s['state'], 'error' => $s['error'], 'offset' => $from + strlen($data), 'data' => base64_encode($data), 'more' => $from + strlen($data) < $s['base'] + strlen($s['out']), 'lines' => $lines];
        // A session that ended is forgotten once its last words were read.
        if (in_array($s['state'], ['closed', 'failed'], true) && !$answer['more']) {
            unset($this->sessions[$id]);
        }
        return $answer;
    }

    /** @return array<string, mixed> */
    private function open(int $oltId, int $user, int $cols, int $rows): array
    {
        $olt = Olts::find($oltId);
        if (!$olt || $user <= 0) {
            return ['ok' => false, 'error' => 'OLT not found.'];
        }
        if (!OltManager::canChange($olt)) {
            return ['ok' => false, 'error' => 'The console needs telnet and Allow changes from the portal turned on for this OLT, under Edit.'];
        }
        if (count($this->sessions) >= self::MAX_SESSIONS) {
            return ['ok' => false, 'error' => 'Too many console sessions are open on this server. Close one and try again.'];
        }
        if (count(array_filter($this->sessions, static fn (array $s) => $s['olt'] === $oltId)) >= self::PER_OLT) {
            return ['ok' => false, 'error' => 'Two console sessions are already open on this OLT, which is as many as it should serve beside the portal\'s own reads. Close one and try again.'];
        }
        $host = (string) $olt['host'];
        $socket = @stream_socket_client('tcp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . (int) $olt['telnet_port'], $errno, $error, 0.0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if (!$socket) {
            return ['ok' => false, 'error' => 'Cannot reach the OLT\'s telnet port: ' . $error];
        }
        stream_set_blocking($socket, false);
        $id = bin2hex(random_bytes(16));
        $this->sessions[$id] = [
            'olt' => $oltId, 'user' => $user, 'socket' => $socket, 'state' => 'login', 'stage' => 'user', 'error' => '',
            'login' => '', 'out' => '', 'base' => 0, 'pending' => '', 'line' => '', 'naws' => false,
            'cols' => max(40, min(400, $cols)), 'rows' => max(10, min(200, $rows)), 'created' => time(), 'touched' => time(),
            'name' => (string) $olt['name'], 'credentials' => [Secrets::reveal($olt['telnet_user'] ?? ''), Secrets::reveal($olt['telnet_password'] ?? '')],
        ];
        return ['ok' => true, 'session' => $id];
    }

    private function receive(string $id): void
    {
        $socket = $this->sessions[$id]['socket'];
        $chunk = @fread($socket, 65536);
        if ($chunk === '' || $chunk === false) {
            if (feof($socket)) {
                $this->end($id, $this->sessions[$id]['state'] === 'login' ? 'The OLT closed the connection before the login finished.' : 'The OLT closed the session.');
            }
            return;
        }
        $text = $this->telnet($id, $this->sessions[$id]['pending'] . $chunk);
        if ($this->sessions[$id]['state'] === 'login') {
            $this->login($id, $text);
        } elseif ($this->sessions[$id]['state'] === 'ready') {
            $this->keep($id, $text);
        }
    }

    /** Answers the OLT's telnet options (it echoes, and sends characters as they come) and returns the text. */
    private function telnet(string $id, string $data): string
    {
        $out = '';
        $length = strlen($data);
        $this->sessions[$id]['pending'] = '';
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($data[$i]);
            if ($byte !== self::IAC) {
                $out .= $data[$i];
                continue;
            }
            if ($i + 1 >= $length) {
                $this->sessions[$id]['pending'] = substr($data, $i);
                break;
            }
            $verb = ord($data[$i + 1]);
            if ($verb === self::IAC) {
                $out .= "\xff";
                $i++;
            } elseif (in_array($verb, [self::DO_, self::DONT, self::WILL, self::WONT], true)) {
                if ($i + 2 >= $length) {
                    $this->sessions[$id]['pending'] = substr($data, $i);
                    break;
                }
                $option = ord($data[$i + 2]);
                if ($verb === self::WILL) {
                    $this->write($id, chr(self::IAC) . chr(in_array($option, [self::ECHO_, self::SGA], true) ? self::DO_ : self::DONT) . chr($option));
                } elseif ($verb === self::DO_) {
                    if ($option === self::NAWS) {
                        $this->write($id, chr(self::IAC) . chr(self::WILL) . chr(self::NAWS));
                        $this->sessions[$id]['naws'] = true;
                        $this->resize($id, $this->sessions[$id]['cols'], $this->sessions[$id]['rows'], true);
                    } else {
                        $this->write($id, chr(self::IAC) . chr($option === self::SGA ? self::WILL : self::WONT) . chr($option));
                    }
                }
                $i += 2;
            } elseif ($verb === self::SB) {
                $end = strpos($data, chr(self::IAC) . chr(self::SE), $i + 2);
                if ($end === false) {
                    $this->sessions[$id]['pending'] = substr($data, $i);
                    break;
                }
                $i = $end + 1;
            } else {
                $i++;
            }
        }
        return $out;
    }

    /** Logs in with the OLT's telnet user, out of the browser's sight, then shows the console from the first prompt. */
    private function login(string $id, string $text): void
    {
        $s = &$this->sessions[$id];
        $s['login'] = substr($s['login'] . $text, -4000);
        [$user, $password] = $s['credentials'];
        if ($s['stage'] === 'user' && preg_match('/(user\s*name|login)\s*:\s*$/i', $s['login'])) {
            $this->write($id, $user . "\r\n");
            $s['stage'] = 'password';
            $s['login'] = '';
        } elseif ($s['stage'] === 'password' && preg_match('/password\s*:\s*$/i', $s['login'])) {
            $this->write($id, $password . "\r\n");
            $s['stage'] = 'prompt';
            $s['login'] = '';
        } elseif ($s['stage'] === 'prompt' && !preg_match('/[>#]\s*$/', $s['login']) && preg_match('/(user\s*name\s*:\s*$)|password\s+invalid|invalid\s+password|reenter/i', $s['login'])) {
            // Only the OLT's own refusal: a banner may well mention failed logins.
            $this->end($id, 'The OLT refused the telnet user and password saved for it. Check them under Edit.', 'failed');
        } elseif (in_array($s['stage'], ['prompt', 'enable'], true) && preg_match('/[>#]\s*$/', $s['login'])) {
            if ($s['stage'] === 'prompt' && preg_match('/>\s*$/', $s['login'])) {
                $this->write($id, "enable\r\n");
                $s['stage'] = 'enable';
                $s['login'] = '';
                return;
            }
            $s['state'] = 'ready';
            $s['credentials'] = ['', ''];
            $prompt = trim((string) preg_replace('/^.*\n/s', '', str_replace("\r", '', $s['login'])));
            $this->keep($id, "\x1b[2m-- Connected to " . $s['name'] . '. Everything typed here goes to the OLT; each line is kept in the audit log. --' . "\x1b[0m\r\n" . $prompt);
        }
    }

    private function keep(string $id, string $text): void
    {
        $s = &$this->sessions[$id];
        $s['out'] .= $text;
        if (strlen($s['out']) > self::KEEP_BYTES) {
            $drop = strlen($s['out']) - self::KEEP_BYTES;
            $s['out'] = substr($s['out'], $drop);
            $s['base'] += $drop;
        }
    }

    /**
     * The lines the operator finished with Enter, as well as they can be told from the keys: for the audit log.
     * Completion (Tab) and recalled lines (arrow keys) happen on the OLT, so such a line is marked as partly unknown.
     *
     * @return list<string>
     */
    private function typed(string $id, string $input): array
    {
        $line = $this->sessions[$id]['line'];
        $lines = [];
        $input = (string) preg_replace('/\x1b(\[[0-9;?]*[ -\/]*[@-~]|O.|.)/', "\x01", $input);
        foreach (str_split($input) as $c) {
            if ($c === "\r" || $c === "\n") {
                if (trim($line) !== '') {
                    $lines[] = trim($line);
                }
                $line = '';
            } elseif ($c === "\x7f" || $c === "\x08") {
                $line = substr($line, 0, -1);
            } elseif ($c === "\x15" || $c === "\x03") {
                $line = '';
            } elseif ($c === "\t" || $c === "\x01") {
                $line .= ($line === '' || !str_ends_with($line, '…')) ? '…' : '';
            } elseif (ord($c) >= 32) {
                $line .= $c;
            }
        }
        $this->sessions[$id]['line'] = substr($line, -512);
        return array_values(array_unique($lines));
    }

    private function resize(string $id, int $cols, int $rows, bool $force = false): void
    {
        $s = &$this->sessions[$id];
        if ($cols <= 0 || $rows <= 0) {
            return;
        }
        $cols = max(40, min(400, $cols));
        $rows = max(10, min(200, $rows));
        if (!$force && $cols === $s['cols'] && $rows === $s['rows']) {
            return;
        }
        $s['cols'] = $cols;
        $s['rows'] = $rows;
        if ($s['naws']) {
            $size = str_replace("\xff", "\xff\xff", pack('nn', $cols, $rows));
            $this->write($id, chr(self::IAC) . chr(self::SB) . chr(self::NAWS) . $size . chr(self::IAC) . chr(self::SE));
        }
    }

    private function write(string $id, string $bytes): void
    {
        $socket = $this->sessions[$id]['socket'] ?? null;
        if (!is_resource($socket)) {
            return;
        }
        // The socket does not block: what does not fit now is sent as soon as it can be, in order.
        $sent = 0;
        $until = microtime(true) + 2.0;
        while ($sent < strlen($bytes) && microtime(true) < $until) {
            $n = @fwrite($socket, substr($bytes, $sent));
            if ($n === false) {
                $this->end($id, 'The connection to the OLT broke.');
                return;
            }
            $sent += $n;
            if ($sent < strlen($bytes)) {
                usleep(10000);
            }
        }
    }

    private function expire(): void
    {
        $now = time();
        foreach ($this->sessions as $id => $s) {
            if ($s['state'] === 'login' && $now - $s['created'] > self::LOGIN_SECONDS) {
                $this->end($id, 'The OLT did not finish the login in ' . self::LOGIN_SECONDS . ' seconds.', 'failed');
            } elseif ($s['state'] === 'ready' && $now - $s['touched'] > self::IDLE_SECONDS) {
                $this->end($id, 'Closed after ' . (self::IDLE_SECONDS / 60) . ' minutes without use.');
            } elseif ($s['state'] === 'ready' && $now - $s['created'] > self::MAX_SECONDS) {
                $this->end($id, 'Closed after ' . (self::MAX_SECONDS / 3600) . ' hours. Open a new session to go on.');
            } elseif (in_array($s['state'], ['closed', 'failed'], true) && $now - $s['touched'] > 120) {
                unset($this->sessions[$id]);
            }
        }
    }

    private function end(string $id, string $why, string $state = 'closed'): void
    {
        if (!isset($this->sessions[$id])) {
            return;
        }
        $s = &$this->sessions[$id];
        if (is_resource($s['socket'])) {
            @fclose($s['socket']);
        }
        $s['socket'] = null;
        $s['credentials'] = ['', ''];
        if (in_array($s['state'], ['closed', 'failed'], true)) {
            return;
        }
        $s['state'] = $state;
        $s['error'] = $why;
        if ($why !== '') {
            $this->keep($id, "\r\n\x1b[2m-- " . $why . " --\x1b[0m\r\n");
        }
    }
}
