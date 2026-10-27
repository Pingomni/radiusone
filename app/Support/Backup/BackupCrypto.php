<?php

declare(strict_types=1);

namespace App\Support\Backup;

use RuntimeException;

/**
 * Passphrase encryption for backups that leave the server (libsodium): an Argon2id key from the
 * passphrase and a per-file salt, then an XChaCha20-Poly1305 secretstream in 1 MiB chunks.
 * File layout: "FRWEBBK1" | salt(16) | opslimit(u32) | memlimit(u64) | stream header(24) | chunks,
 * each chunk "length(u32) | ciphertext". The last chunk carries the FINAL tag, so a truncated or
 * altered file fails to decrypt instead of restoring partially.
 */
final class BackupCrypto
{
    public const EXTENSION = '.enc';
    private const MAGIC = 'FRWEBBK1';
    private const CHUNK = 1048576;

    public static function encrypt(string $source, string $target, string $passphrase): void
    {
        self::assertPassphrase($passphrase);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
        $key = self::key($passphrase, $salt, $ops, $mem);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        sodium_memzero($key);
        [$in, $out] = self::open($source, $target);
        try {
            fwrite($out, self::MAGIC . $salt . pack('N', $ops) . pack('J', $mem) . $header);
            $chunk = fread($in, self::CHUNK);
            do {
                $next = feof($in) ? '' : fread($in, self::CHUNK);
                $last = $next === '' || $next === false;
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, (string) $chunk, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                fwrite($out, pack('N', strlen($cipher)) . $cipher);
                $chunk = $next;
            } while (!$last);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function decrypt(string $source, string $target, string $passphrase): void
    {
        [$in, $out] = self::open($source, $target);
        $ok = false;
        try {
            $head = fread($in, 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + 4 + 8 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($head === false || !str_starts_with($head, self::MAGIC) || strlen($head) < 60) {
                throw new RuntimeException('Not a RadiusOne encrypted backup.');
            }
            $salt = substr($head, 8, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            ['ops' => $ops, 'mem' => $mem] = unpack('Nops/Jmem', substr($head, 24, 12)) ?: throw new RuntimeException('Backup header is damaged.');
            $key = self::key($passphrase, $salt, $ops, $mem);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($head, 36), $key);
            sodium_memzero($key);
            while (!feof($in)) {
                $length = fread($in, 4);
                if ($length === '' || $length === false) {
                    break;
                }
                if (strlen($length) !== 4) {
                    throw new RuntimeException('Backup is incomplete (truncated).');
                }
                $size = unpack('Nsize', $length)['size'];
                if ($size > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Backup is damaged.');
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, (string) fread($in, $size));
                if ($result === false) {
                    throw new RuntimeException('Wrong passphrase, or the backup is damaged.');
                }
                fwrite($out, $result[0]);
                if ($result[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw new RuntimeException('Backup is incomplete (truncated).');
            }
        } finally {
            fclose($in);
            fclose($out);
            if (!$ok) {
                @unlink($target);
            }
        }
    }

    public static function assertPassphrase(string $passphrase): void
    {
        if (strlen($passphrase) < 12) {
            throw new \InvalidArgumentException('Use a backup passphrase of at least 12 characters.');
        }
    }

    private static function key(string $passphrase, string $salt, int $ops, int $mem): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    /** @return array{0: resource, 1: resource} */
    private static function open(string $source, string $target): array
    {
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot read ' . basename($source) . '.');
        }
        $old = umask(0077);
        $out = fopen($target, 'xb');
        umask($old);
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Cannot create ' . basename($target) . ' (it may already exist).');
        }
        return [$in, $out];
    }
}
