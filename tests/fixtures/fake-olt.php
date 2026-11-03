<?php

declare(strict_types=1);

/**
 * A pretend Huawei MA5800 for the tests: answers SNMP reads over UDP and runs a telnet command line over TCP, on the
 * loopback address only. Every SNMP request type and every telnet line it receives is written to a log file.
 * Usage: php fake-olt.php <config.json>
 */

use App\Support\Snmp;

require __DIR__ . '/../../vendor/autoload.php';

$config = json_decode((string) file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$log = static function (string $line) use ($config): void {
    file_put_contents($config['log'], $line . "\n", FILE_APPEND);
};

$tree = [];
foreach ($config['oids'] as $oid => [$tag, $value]) {
    $tree[$oid] = [$tag, $tag === 4 ? base64_decode($value, true) : $value];
}
uksort($tree, [Snmp::class, 'compare']);
$oids = array_keys($tree);

$udp = stream_socket_server('udp://127.0.0.1:' . $config['snmp_port'], $errno, $error, STREAM_SERVER_BIND);
$tcp = stream_socket_server('tcp://127.0.0.1:' . $config['telnet_port'], $errno, $error);
if (!$udp || !$tcp) {
    fwrite(STDERR, $error);
    exit(1);
}
file_put_contents($config['ready'], 'ready');

$next = static function (string $oid) use ($oids): ?string {
    foreach ($oids as $candidate) {
        if (Snmp::compare($candidate, $oid) > 0) {
            return $candidate;
        }
    }
    return null;
};

$answer = static function ($socket) use ($config, $tree, $next, $log): void {
    $packet = stream_socket_recvfrom($socket, 65535, 0, $peer);
    $request = Snmp::decodeRequest((string) $packet);
    if ($request === null || $request['community'] !== $config['community']) {
        return; // A real OLT stays silent for a wrong community.
    }
    $log('snmp ' . dechex($request['type']));
    $binds = [];
    if ($request['type'] === 0xA0) {
        foreach ($request['oids'] as $oid) {
            $binds[] = isset($tree[$oid]) ? [$oid, $tree[$oid][0], $tree[$oid][1]] : [$oid, 0x80, ''];
        }
    } elseif ($request['type'] === 0xA5 || $request['type'] === 0xA1) {
        $cursor = $request['oids'][0];
        for ($i = 0; $i < max(1, (int) $request['max_repetitions']); $i++) {
            $cursor = $next($cursor);
            if ($cursor === null) {
                $binds[] = [$request['oids'][0], 0x82, ''];
                break;
            }
            $binds[] = [$cursor, $tree[$cursor][0], $tree[$cursor][1]];
        }
    } else {
        return; // Anything else, such as a SET, is logged above and never answered.
    }
    stream_socket_sendto($socket, Snmp::encodeResponse($request['community'], (int) $request['version'], (int) $request['id'], $binds), 0, $peer);
};

$client = null;
$buffer = '';
$state = 'closed';
$say = static function (string $text) use (&$client): void {
    @fwrite($client, str_replace("\n", "\r\n", $text));
};
$prompt = static function () use (&$state, $say): void {
    $say("\n" . ($state === 'enable' ? 'MA5800-X7#' : 'MA5800-X7>'));
};
$table = $config['mac_table'];
$line = static function (string $text) use (&$client, &$state, &$table, $config, $say, $prompt, $log): void {
    switch ($state) {
        case 'user':
            $log('telnet-user');
            $state = $text === $config['user'] ? 'password' : 'bad-user';
            $say("\n>>User password:");
            return;
        case 'bad-user':
        case 'password':
            $log('telnet-password');
            if ($state === 'bad-user' || $text !== $config['password']) {
                $say("\n  Username or password invalid.\n\n>>User name:");
                $state = 'user';
                return;
            }
            $state = 'login';
            $say("\n  Number of failed logins before this one: 0\n");
            $prompt();
            return;
        case 'scroll-question':
            $state = 'enable';
            $say("\n  Command:\n          scroll\n");
            $prompt();
            return;
        case 'table-question':
            $parts = explode('@MORE@', $table);
            $say("\n  It will take some time, please wait...\n" . array_shift($parts));
            if ($parts) {
                $table = implode('@MORE@', $parts);
                $state = 'more';
                $say("\n---- More ( Press 'Q' to break ) ----");
            } else {
                $state = 'enable';
                $prompt();
            }
            return;
        case 'logout':
            $log('telnet-cmd ' . $text);
            $state = 'closed';
            fclose($client);
            $client = null;
            return;
    }
    $log('telnet-cmd ' . $text);
    match ($text) {
        'enable' => (static function () use (&$state, $prompt) {
            $state = 'enable';
            $prompt();
        })(),
        'scroll' => (static function () use (&$state, $say) {
            $state = 'scroll-question';
            $say("\n{ <cr>|number<U><10,512> }:");
        })(),
        'display mac-address all' => (static function () use (&$state, $say) {
            $state = 'table-question';
            $say("\n{ <cr>||<K> }:");
        })(),
        'quit' => (static function () use (&$state, $say) {
            $state = 'logout';
            $say("\n  Are you sure to log out? (y/n)[n]:");
        })(),
        default => $say("\n  % Unknown command, the error locates at '^'\n"),
    };
    if (!in_array($text, ['enable', 'scroll', 'display mac-address all', 'quit'], true)) {
        $prompt();
    }
};

$until = time() + 300;
while (time() < $until) {
    $read = [$udp, $tcp];
    if ($client) {
        $read[] = $client;
    }
    $none = null;
    if (!stream_select($read, $none, $none, 1)) {
        continue;
    }
    foreach ($read as $socket) {
        if ($socket === $udp) {
            $answer($udp);
        } elseif ($socket === $tcp) {
            $client = stream_socket_accept($tcp, 1);
            stream_set_blocking($client, false);
            $state = 'user';
            $buffer = '';
            $table = $config['mac_table'];
            // Asks for an option, as telnet servers do, split so a reply has to cope with it.
            fwrite($client, chr(255) . chr(251) . chr(1) . chr(255) . chr(253) . chr(31));
            fwrite($client, "\e[0mWarning: authorised use only\r\n>>User name:");
        } else {
            $data = fread($client, 4096);
            if ($data === '' || $data === false) {
                if (feof($client)) {
                    fclose($client);
                    $client = null;
                }
                continue;
            }
            // Drop the client's replies to the options.
            $data = (string) preg_replace('/\xff[\xfb-\xfe]./s', '', $data);
            if ($state === 'more' && str_contains($data, ' ')) {
                $data = str_replace(' ', '', $data);
                $parts = explode('@MORE@', $table);
                // Like the OLT: the cursor moves back over the "More" line, and the next page starts on that line.
                $say("\e[37D" . str_repeat(' ', 37) . "\e[37D" . array_shift($parts));
                if ($parts) {
                    $table = implode('@MORE@', $parts);
                    $say("\n---- More ( Press 'Q' to break ) ----");
                } else {
                    $state = 'enable';
                    $prompt();
                }
            }
            $buffer .= $data;
            while ($client && ($at = strpos($buffer, "\n")) !== false) {
                $text = rtrim(substr($buffer, 0, $at), "\r");
                $buffer = substr($buffer, $at + 1);
                $line($text);
            }
        }
    }
}
