<?php

declare(strict_types=1);

namespace App\Support\Olt;

use RuntimeException;

/**
 * Telnet to the OLT's command line, for reading only. It can send nothing but the commands in ALLOWED: there is no way
 * to pass it another one, so nothing in the portal can change the OLT through this class.
 */
final class OltCli
{
    /** enable and scroll only affect this login: scroll stops the output pausing, and nothing is saved on the OLT. */
    private const ALLOWED = ['enable', 'scroll', 'display mac-address all', 'quit', 'y'];
    /** Telnet: IAC, then what to do with an option. */
    private const IAC = 255;
    private const DONT = 254;
    private const DO_ = 253;
    private const WONT = 252;
    private const WILL = 251;
    private const MAX_BYTES = 33554432;

    /** @var resource|null */
    private $socket;
    private string $pending = '';
    private float $deadline;

    public function __construct(private string $host, private int $port, private string $user, private string $password, float $budget = 240.0)
    {
        $this->deadline = microtime(true) + $budget;
    }

    /** Logs in, reads the MAC table and logs out. */
    public function macTable(): string
    {
        $this->login();
        try {
            $this->send('scroll');
            $this->readUntilPrompt();
            $this->send('display mac-address all');
            return $this->readUntilPrompt();
        } finally {
            $this->logout();
        }
    }

    /** Only proves that this user can log in. */
    public function check(): void
    {
        $this->login();
        $this->logout();
    }

    private function login(): void
    {
        $socket = @stream_socket_client('tcp://' . (str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host) . ':' . $this->port, $errno, $error, 10);
        if (!$socket) {
            throw new RuntimeException('Cannot reach the OLT\'s telnet port ' . $this->port . ': ' . $error);
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->waitFor('/(user\s*name|login)\s*:\s*$/i', 'the login prompt');
        $this->write($this->user);
        $this->waitFor('/password\s*:\s*$/i', 'the password prompt');
        $this->write($this->password);
        $answer = $this->waitFor('/([>#]\s*$)|(user\s*name\s*:\s*$)|invalid|reenter/i', 'an answer to the login');
        if (!preg_match('/[>#]\s*$/', $answer)) {
            throw new RuntimeException('The OLT refused this telnet user and password.');
        }
        if (preg_match('/>\s*$/', $answer)) {
            $this->send('enable');
            $this->readUntilPrompt();
        }
    }

    private function logout(): void
    {
        if (!is_resource($this->socket)) {
            return;
        }
        try {
            $this->send('quit');
            // "Are you sure to log out? (y/n)". The answer is best effort: closing the connection also ends the login.
            $this->waitFor('/\(y\/n\).*$/i', 'the logout question', 3.0);
            $this->send('y');
        } catch (RuntimeException) {
        }
        fclose($this->socket);
        $this->socket = null;
    }

    private function send(string $command): void
    {
        if (!in_array($command, self::ALLOWED, true)) {
            throw new RuntimeException('This command is not allowed on the OLT.');
        }
        $this->write($command);
    }

    private function write(string $line): void
    {
        if (@fwrite($this->socket, $line . "\r\n") === false) {
            throw new RuntimeException('The OLT closed the telnet connection.');
        }
    }

    /** Everything up to the next command prompt. Answers the OLT's "press Enter" and "--More--" pauses by itself. */
    private function readUntilPrompt(): string
    {
        $all = '';
        $tail = '';
        while (true) {
            $chunk = $this->read(microtime(true) + 60);
            $all .= $chunk;
            $tail = substr($tail . $chunk, -200);
            if (strlen($all) > self::MAX_BYTES) {
                throw new RuntimeException('The OLT sent more than this portal will read.');
            }
            if (preg_match('/more\s*\(\s*press[^)]*\)\s*-*\s*$/i', $tail) || preg_match('/-+\s*more\s*-*\s*$/i', $tail)) {
                fwrite($this->socket, ' ');
                $tail = '';
            } elseif (preg_match('/\{[^{}]*<cr>[^{}]*\}\s*:\s*$/i', $tail)) {
                fwrite($this->socket, "\r\n");
                $tail = '';
            } elseif (preg_match('/^\s*[^\s>#]*[>#]\s*$/m', substr($tail, (int) strrpos($tail, "\n"))) && preg_match('/[>#]\s*$/', $tail)) {
                return $all;
            }
        }
    }

    private function waitFor(string $pattern, string $what, float $seconds = 20.0): string
    {
        $buffer = '';
        $until = microtime(true) + $seconds;
        while (true) {
            try {
                $buffer .= $this->read($until);
            } catch (RuntimeException $e) {
                throw new RuntimeException('Waiting for ' . $what . ': ' . $e->getMessage(), 0, $e);
            }
            if (preg_match($pattern, $buffer)) {
                return $buffer;
            }
        }
    }

    /** What arrived, without telnet negotiation and terminal codes. Throws when nothing comes by $until. */
    private function read(float $until): string
    {
        while (true) {
            $wait = min($until, $this->deadline) - microtime(true);
            if ($wait <= 0) {
                throw new RuntimeException('The OLT took too long to answer.');
            }
            $read = [$this->socket];
            $none = null;
            $ready = @stream_select($read, $none, $none, (int) $wait, (int) (fmod($wait, 1) * 1e6));
            if ($ready === false) {
                throw new RuntimeException('Reading from the OLT failed.');
            }
            if ($ready === 0) {
                continue;
            }
            $data = fread($this->socket, 65536);
            if ($data === '' || $data === false) {
                if (feof($this->socket)) {
                    throw new RuntimeException('The OLT closed the telnet connection.');
                }
                continue;
            }
            $text = $this->clean($this->pending . $data);
            if ($text !== '') {
                return $text;
            }
        }
    }

    private function clean(string $data): string
    {
        $out = '';
        $length = strlen($data);
        $this->pending = '';
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($data[$i]);
            if ($byte !== self::IAC) {
                $out .= $data[$i];
                continue;
            }
            if ($i + 1 >= $length) {
                $this->pending = substr($data, $i);
                break;
            }
            $verb = ord($data[$i + 1]);
            if (in_array($verb, [self::DO_, self::DONT, self::WILL, self::WONT], true)) {
                if ($i + 2 >= $length) {
                    $this->pending = substr($data, $i);
                    break;
                }
                // Decline every option: plain text is all this needs.
                if ($verb === self::DO_ || $verb === self::WILL) {
                    fwrite($this->socket, chr(self::IAC) . chr($verb === self::DO_ ? self::WONT : self::DONT) . $data[$i + 2]);
                }
                $i += 2;
            } elseif ($verb === self::IAC) {
                $out .= chr(self::IAC);
                $i++;
            } else {
                $i++;
            }
        }
        return (string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $out);
    }
}
