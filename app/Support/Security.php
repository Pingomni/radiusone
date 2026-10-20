<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Request-independent security helpers. CSRF, sessions and login throttling are handled by
 * Laravel middleware and RateLimiter; trusted proxies are configured in bootstrap/app.php.
 */
final class Security
{
    private const UPLOAD_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function randomPassword(int $length = 14): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $password;
    }

    /** Client address after trusted-proxy resolution; 'cli' outside HTTP. */
    public static function clientIp(): string
    {
        if (app()->runningInConsole()) {
            return 'cli';
        }
        $ip = (string) request()->ip();
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    /** Validated upload from the current request; null when the field is empty. */
    public static function uploadInfo(string $field, array $allowedMimes, int $maxBytes): ?array
    {
        $file = request()->file($field);
        if ($file === null) {
            return null;
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new \RuntimeException('Upload failed.');
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > $maxBytes) {
            throw new \RuntimeException('Uploaded file is too large.');
        }

        $path = (string) $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        if (!in_array($mime, $allowedMimes, true) || !isset(self::UPLOAD_MIME_EXTENSIONS[$mime])) {
            throw new \RuntimeException('Uploaded file type is not allowed.');
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($path) === false) {
            throw new \RuntimeException('Uploaded image is invalid.');
        }

        return [
            'tmp_name' => $path,
            'name' => basename($file->getClientOriginalName() ?: 'upload'),
            'mime' => $mime,
            'extension' => self::UPLOAD_MIME_EXTENSIONS[$mime],
            'size' => $size,
        ];
    }

    public static function safeFilenameStem(string $value, string $fallback = 'file'): string
    {
        $stem = strtolower(trim(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? '', '-._'));
        $stem = substr($stem, 0, 80);
        return $stem !== '' ? $stem : $fallback;
    }

    public static function assertHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('URL must start with http:// or https://.');
        }
    }

    public static function twoFactorSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function twoFactorUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function verifyTotp(string $secret, string $code, int $window = 1): bool
    {
        return self::totpCounter($secret, $code, $window) !== null;
    }

    public static function totpCounter(string $secret, string $code, int $window = 1): ?int
    {
        if (!preg_match('/^[A-Z2-7]{16,128}$/Di', $secret) || !preg_match('/^\d{6}$/D', $code)) {
            return null;
        }
        $counter = intdiv(time(), 30);
        for ($offset = -min(1, max(0, $window)); $offset <= min(1, max(0, $window)); $offset++) {
            if (hash_equals(self::totpAt($secret, $counter + $offset), $code)) {
                return $counter + $offset;
            }
        }
        return null;
    }

    private static function totpAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            throw new \RuntimeException('Invalid authenticator secret.');
        }

        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';
        foreach (str_split($secret) as $char) {
            if (!isset($alphabet[$char])) {
                return '';
            }
            $bits .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
