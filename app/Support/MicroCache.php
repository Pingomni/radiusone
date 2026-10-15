<?php
declare(strict_types=1);
namespace App\Support;

/**
 * A few seconds of caching for live panels that refresh in several browsers at once (service checks,
 * pings). Values are JSON in a private 0700 directory under the system temp dir; if that directory
 * is missing, not ours or not writable, the value is simply computed every time.
 */
final class MicroCache
{
    public static function remember(string $key, int $seconds, callable $compute): mixed
    {
        $file = self::file($key);
        if ($file !== null && is_file($file) && time() - (int) @filemtime($file) < $seconds) {
            $raw = @file_get_contents($file);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (is_array($decoded) && array_key_exists('v', $decoded)) {
                return $decoded['v'];
            }
        }
        $value = $compute();
        if ($file !== null) {
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, json_encode(['v' => $value], JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
                @rename($tmp, $file);
            }
        }
        return $value;
    }

    private static function file(string $key): ?string
    {
        try {
            $scope = substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 12);
        } catch (\Throwable) {
            return null;
        }
        $dir = sys_get_temp_dir() . '/frweb-cache-' . $scope;
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            return null;
        }
        // Never trust a directory another user created first.
        if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) {
            return null;
        }
        return $dir . '/' . hash('sha256', $key) . '.json';
    }
}
