<?php

declare(strict_types=1);

namespace App\Http\Support;

/** Display formatting shared by controllers (ported from the legacy app/Http/format.php). */
final class Format
{
    public static function slugKey(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $value), '_'));
        return $slug !== '' ? substr($slug, 0, 80) : 'ip_pool_' . bin2hex(random_bytes(4));
    }

    public static function pppoeSessionTime(array $session): string
    {
        $active = !array_key_exists('acctstoptime', $session) || empty($session['acctstoptime']);
        $start = strtotime((string) ($session['acctstarttime'] ?? ''));
        if ($active && $start && $start > strtotime('2000-01-01')) {
            return self::seconds(max(0, time() - $start));
        }
        $sessionTime = (int) ($session['acctsessiontime'] ?? 0);
        if ($sessionTime > 0) {
            return self::seconds($sessionTime);
        }
        if ($start && $start > strtotime('2000-01-01')) {
            return self::seconds(max(0, time() - $start));
        }
        return 'waiting for interim update';
    }

    /** Operator wording for an accounting state: online, no recent update, offline, ended. */
    public static function connectionLabel(string $state): string
    {
        return match ($state) {
            'fresh accounting' => 'online',
            'stale accounting' => 'no recent update',
            'no fresh accounting' => 'offline',
            'closed' => 'ended',
            default => $state,
        };
    }

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }

    public static function acctDownload(array $row): int
    {
        return (int) ($row['acctoutputoctets'] ?? $row['down'] ?? 0);
    }

    public static function acctUpload(array $row): int
    {
        return (int) ($row['acctinputoctets'] ?? $row['up'] ?? 0);
    }

    public static function seconds(int $seconds): string
    {
        return sprintf('%02dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }

    public static function maskSecret(string $attribute, string $value): string
    {
        return strtolower($attribute) === 'cleartext-password' ? '********' : $value;
    }

    /** Database timestamp trimmed to seconds ('' stays ''). */
    public static function time(?string $value): string
    {
        $value = (string) $value;
        return $value === '' || $value === '-' ? '' : substr($value, 0, 19);
    }
}
