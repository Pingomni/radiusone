<?php

declare(strict_types=1);

namespace App\Support;

/** Key/value workspace settings; secret values are sealed with Secrets. */
final class Settings
{
    public static function value(string $key, string $default = ''): string
    {
        try {
            $value = Db::value('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        } catch (\Throwable) {
            return $default;
        }
        return $value === false || $value === null ? $default : Secrets::reveal((string) $value);
    }

    public static function save(string $key, string $value, bool $secret = false): void
    {
        $user = Auth::user();
        Db::exec(
            'INSERT INTO settings (setting_key, setting_value, is_secret, updated_by, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), is_secret=VALUES(is_secret), updated_by=VALUES(updated_by), updated_at=NOW()',
            [$key, $secret ? Secrets::encrypt($value) : $value, $secret ? 1 : 0, $user['id'] ?? null]
        );
    }

    /** Browser-loadable logo source, or null for the default mark. */
    public static function logo(): ?string
    {
        return Branding::source(self::value('portal_logo_path', ''));
    }
}
