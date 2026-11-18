<?php

declare(strict_types=1);

namespace App\Support\Olt;

use RuntimeException;

/**
 * A telnet login on the OLT's command line. It sends whatever line it is given, so nothing outside this folder uses it:
 * OltCli only lets reads through, and OltManager only sends the fixed commands it builds itself.
 */
final class OltTelnet
{
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
    /** The name in the prompt, learned at login: only a line made of it ends a command's output. */
    private string $name = '';

    public function __construct(private string $host, private int $port, private string $user, private string $password, float $budget = 240.0)
    {
        $this->deadline = microtime(true) + $budget;
    }

    /** Logs in and enters privilege mode. Changes nothing on the OLT. */
    public function open(): void
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
        if (!preg_match('/([^\s>#()]+)(\([^\s()]*\))?[>#]\s*$/D', $answer, $match)) {
            throw new RuntimeException('The OLT refused this telnet user and password.');
        }
        $this->name = $match[1];
        // A real MA5800 can print a notice and a second prompt a moment after the first one ("The user password is
        // set by other administrator..."). Taken for the answer to the next command, it would shift every answer by one.
        $answer .= $this->drain(0.6);
        if (preg_match('/>\s*$/', $answer)) {
            $this->command('enable');
        }
    }

    /** Reads and returns whatever the OLT is still sending, until it has been quiet for $quiet seconds. */
    private function drain(float $quiet): string
    {
        $all = '';
        $stop = microtime(true) + 5.0;
        while (microtime(true) < $stop && is_resource($this->socket)) {
            $read = [$this->socket];
            $none = null;
            if (!@stream_select($read, $none, $none, (int) $quiet, (int) (fmod($quiet, 1) * 1e6))) {
                break;
            }
            $data = fread($this->socket, 65536);
            if ($data === '' || $data === false) {
                break;
            }
            $all .= $this->clean($this->pending . $data);
        }
        return $all;
    }

    /** Best effort: closing the connection also ends the login. */
    public function close(): void
    {
        if (!is_resource($this->socket)) {
            return;
        }
        try {
            // Out of interface and config mode first; at the top, quit asks "Are you sure to log out? (y/n)".
            for ($i = 0; $i < 4; $i++) {
                $this->write('quit');
                if (preg_match('/\(y\/n\)/i', $this->waitFor('/\(y\/n\)[^\n]*$|[>#]\s*$/i', 'the logout question', 3.0))) {
                    $this->write('y');
                    break;
                }
            }
        } catch (RuntimeException) {
        }
        @fclose($this->socket);
        $this->socket = null;
    }

    /**
     * Sends one line and returns what the OLT printed up to its next prompt. The OLT's "press Enter" and "More" pauses
     * are answered by themselves. A yes/no question is answered yes only when $confirm says so, and no otherwise.
     */
    public function command(string $line, bool $confirm = false): string
    {
        // A question mark or a tab makes the OLT act before Enter, and a line break would be a second command.
        if ($line === '' || preg_match('/[\x00-\x1f\x7f?]/', $line)) {
            throw new RuntimeException('This command has characters that cannot be sent to the OLT.');
        }
        // Anything the OLT printed on its own since the last answer is not part of this one.
        $this->drain(0.0);
        $this->write($line);
        $all = '';
        $tail = '';
        $declined = false;
        $prompt = '/(^|\n)\s*' . preg_quote($this->name, '/') . '(\([^\s()]*\))?[>#]\s*$/D';
        while (true) {
            $chunk = $this->read(microtime(true) + 60);
            $all .= $chunk;
            $tail = substr($tail . $chunk, -300);
            if (strlen($all) > self::MAX_BYTES) {
                throw new RuntimeException('The OLT sent more than this portal will read.');
            }
            if (preg_match('/more\s*\(\s*press[^)]*\)\s*-*\s*$/i', $tail) || preg_match('/-+\s*more\s*-*\s*$/i', $tail)) {
                fwrite($this->socket, ' ');
                $tail = '';
            } elseif (preg_match('/\{[^{}]*<cr>[^{}]*\}\s*:\s*$/i', $tail)) {
                fwrite($this->socket, "\r\n");
                $tail = '';
            } elseif (preg_match('/\(y\/n\)\s*(\[[yn]\])?\s*:\s*$/i', $tail)) {
                fwrite($this->socket, ($confirm ? 'y' : 'n') . "\r\n");
                $declined = !$confirm;
                $tail = '';
            } elseif ($this->name !== '' ? preg_match($prompt, $tail) : preg_match('/[>#]\s*$/', $tail)) {
                if ($declined) {
                    throw new RuntimeException('The OLT asked "are you sure" for "' . $line . '", which was not expected, so the answer was no and nothing was done.');
                }
                return $all;
            }
        }
    }

    private function write(string $line): void
    {
        if (!is_resource($this->socket) || @fwrite($this->socket, $line . "\r\n") === false) {
            throw new RuntimeException('The OLT closed the telnet connection.');
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
