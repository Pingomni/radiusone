<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\BoundedProcess;
use RuntimeException;

/**
 * Settings → Backups → Back up now: asks the root helper ops/frweb-backup-now to start the nightly
 * backup at once. It runs in the background as frweb-backup-now.service; its result lands in status.json.
 */
final class BackupNow
{
    public const HELPER = '/usr/local/sbin/frweb-backup-now';
    public const UNIT = 'frweb-backup-now.service';
    /** How the helper is run, and how the running state is read; tests point them at stand-ins. */
    public static array $command = ['sudo', '-n', self::HELPER];
    public static array $isActive = ['systemctl', 'is-active', '--quiet', self::UNIT];

    /** Whether the root helper is installed (ops/apply-service-control.sh). */
    public static function ready(): bool
    {
        return is_executable((string) end(self::$command));
    }

    public static function running(): bool
    {
        try {
            return BoundedProcess::run(self::$isActive, '', 3, 512)['exit'] === 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function start(): void
    {
        if (!self::ready()) {
            throw new RuntimeException('Manual backups are not enabled on this server. Run ops/apply-service-control.sh as root first.');
        }
        $result = BoundedProcess::run(self::$command, '', 30, 8192);
        if ($result['exit'] !== 0) {
            $detail = trim($result['error']) ?: trim($result['output']);
            if (str_contains($detail, 'password is required') || str_contains($detail, 'not allowed')) {
                $detail = 'www-data is not allowed to start backups. Run ops/apply-service-control.sh as root.';
            }
            throw new RuntimeException($detail !== '' ? $detail : 'The backup could not be started.');
        }
    }
}
