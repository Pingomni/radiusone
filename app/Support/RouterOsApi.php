<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** RouterOS API client (TLS, port 8729): sentence framing, replies and the PPP active-session lookups. */
final class RouterOsApi
{
    private static ?\Closure $socketFactory = null;
    private static float $deadline = 0;

    public static function findActivePpp($socket, string $field, string $value): array
    {
        self::writeSentence($socket, ['/ppp/active/print', '?' . $field . '=' . $value]);
        $reply = self::readReply($socket);
        self::assertOk($reply, 'MikroTik API active session lookup failed');
        $rows = [];
        foreach ($reply as $sentence) {
            if (($sentence[0] ?? '') !== '!re') {
                continue;
            }
            $rows[] = self::parseSentence($sentence);
        }
        return $rows;
    }

    public static function connect(string $host)
    {
        $url = parse_url(str_contains($host, '://') ? $host : 'tls://' . $host);
        if (!$url || ($url['scheme'] ?? '') !== 'tls' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['path']) || isset($url['query']) || isset($url['fragment'])) {
            throw new RuntimeException('Use a TLS API host with a trusted certificate.');
        }
        $port = (int) ($url['port'] ?? 8729);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid API port.');
        }
        $options = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $url['host']]];
        self::$deadline = microtime(true) + 8;
        $endpoint = 'tls://' . $url['host'] . ':' . $port;
        $socket = self::$socketFactory ? (self::$socketFactory)($endpoint, $options) : @stream_socket_client($endpoint, $errno, $error, 4, STREAM_CLIENT_CONNECT, stream_context_create($options));
        if (!is_resource($socket)) {
            throw new RuntimeException('Verified TLS API connection failed.');
        }
        return $socket;
    }

    private static function deadline($socket): void
    {
        $remaining = self::$deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('NAS operation timed out; verify the same session before retrying.');
        }
        stream_set_timeout($socket, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
    }

    private static function readBytes($socket, int $length): string
    {
        if ($length < 0 || $length > 65536) {
            throw new RuntimeException('NAS reply exceeds allowed size.');
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            self::deadline($socket);
            $chunk = fread($socket, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('NAS reply incomplete or timed out; verify before retrying.');
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }

    public static function writeSentence($socket, array $words): void
    {
        foreach ($words as $word) {
            self::writeWord($socket, $word);
        }
        self::writeWord($socket, '');
    }

    private static function writeWord($socket, string $word): void
    {
        $bytes = self::encodeLength(strlen($word)) . $word;
        while ($bytes !== '') {
            self::deadline($socket);
            $n = fwrite($socket, $bytes);
            if (!$n) {
                throw new RuntimeException('NAS write failed.');
            } $bytes = substr($bytes, $n);
        }
    }

    public static function readReply($socket): array
    {
        $reply = [];
        while (true) {
            $sentence = self::readSentence($socket);
            if ($sentence === null) {
                throw new RuntimeException('MikroTik API connection closed before a reply was received.');
            }
            if (!$sentence) {
                continue;
            }
            $reply[] = $sentence;
            if (count($reply) > 256) {
                throw new RuntimeException('Too many NAS reply sentences.');
            }
            if (in_array('!done', $sentence, true) || in_array('!fatal', $sentence, true)) {
                return $reply;
            }
        }
    }

    private static function readSentence($socket): ?array
    {
        $sentence = [];
        while (true) {
            $word = self::readWord($socket);
            if ($word === null) {
                return $sentence ?: null;
            }
            if ($word === '') {
                return $sentence;
            }
            $sentence[] = $word;
            if (count($sentence) > 64) {
                throw new RuntimeException('Too many NAS reply words.');
            }
        }
    }

    private static function readWord($socket): ?string
    {
        $c = ord(self::readBytes($socket, 1));
        if (($c & 0x80) === 0) {
            $length = $c;
        } elseif (($c & 0xC0) === 0x80) {
            $length = (($c & 0x3F) << 8) + ord(self::readBytes($socket, 1));
        } elseif (($c & 0xE0) === 0xC0) {
            $b = self::readBytes($socket, 2);
            $length = (($c & 0x1F) << 16) + (ord($b[0]) << 8) + ord($b[1]);
        } elseif (($c & 0xF0) === 0xE0) {
            $b = self::readBytes($socket, 3);
            $length = (($c & 0xF) << 24) + (ord($b[0]) << 16) + (ord($b[1]) << 8) + ord($b[2]);
        } elseif ($c === 0xF0) {
            $length = unpack('N', self::readBytes($socket, 4))[1];
        } else {
            throw new RuntimeException('Invalid NAS word length.');
        }
        return self::readBytes($socket, $length);
    }

    private static function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            return chr(($length >> 8) | 0x80) . chr($length & 0xFF);
        }
        if ($length < 0x200000) {
            return chr(($length >> 16) | 0xC0) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x10000000) {
            return chr(($length >> 24) | 0xE0) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    public static function assertOk(array $reply, string $message): void
    {
        foreach ($reply as $sentence) {
            if (in_array('!trap', $sentence, true) || in_array('!fatal', $sentence, true)) {
                $row = self::parseSentence($sentence);
                throw new RuntimeException($message . ': ' . ($row['message'] ?? 'router returned an error'));
            }
        }
    }

    private static function parseSentence(array $sentence): array
    {
        $row = [];
        foreach ($sentence as $word) {
            if (!str_starts_with($word, '=') || !str_contains(substr($word, 1), '=')) {
                continue;
            }
            [$key, $value] = explode('=', substr($word, 1), 2);
            $row[$key] = $value;
        }
        return $row;
    }
}
