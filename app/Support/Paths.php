<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where RadiusOne keeps things on the server. RadiusOne 2.3 moved them from the freeradius-web names to
 * radiusone ones (ops/radiusone-upgrade-2.3), leaving the old names as links: each path is the new one
 * once it exists, else the old one, so the code also runs on a server that has not moved yet.
 */
final class Paths
{
    /** Configuration and database identities (portal.env, backup.env, migration.env). */
    public static function etc(string $file = ''): string
    {
        return self::join(self::first('/etc/radiusone', '/etc/freeradius-web'), $file);
    }

    /** Documents, branding, security keys, and the update request and status. */
    public static function data(string $file = ''): string
    {
        return self::join(self::first('/var/lib/radiusone', '/var/lib/freeradius-web'), $file);
    }

    public static function logs(string $file = ''): string
    {
        return self::join(self::first('/var/log/radiusone', '/var/log/freeradius-web'), $file);
    }

    /** Logs root writes (backup, time zone): a root-owned folder, so a planted link cannot redirect them. */
    public static function rootLogs(string $file = ''): string
    {
        return self::join(self::first('/var/log/radiusone-root', '/var/log/freeradius-web-root'), $file);
    }

    public static function backups(): string
    {
        return self::first('/var/backups/radiusone', '/var/backups/freeradius-web');
    }

    /** A root helper in /usr/local/sbin: radiusone-<name>, or frweb-<name> before the move. */
    public static function helper(string $name): string
    {
        $new = '/usr/local/sbin/radiusone-' . $name;

        return self::exists($new) || !self::exists('/usr/local/sbin/frweb-' . $name) ? $new : '/usr/local/sbin/frweb-' . $name;
    }

    private static function first(string $new, string $old): string
    {
        return self::exists($new) || !self::exists($old) ? $new : $old;
    }

    private static function exists(string $path): bool
    {
        return @file_exists($path);
    }

    private static function join(string $dir, string $file): string
    {
        return $file === '' ? $dir : $dir . '/' . ltrim($file, '/');
    }
}
