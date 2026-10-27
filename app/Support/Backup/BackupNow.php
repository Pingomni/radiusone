<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\BoundedProcess;
use App\Support\Paths;
use RuntimeException;

/**
 * Settings → Backups → Back up now: asks the root helper ops/radiusone-backup-now to start the nightly
 * backup at once. It runs in the background as radiusone-backup-now.service (frweb-backup-now.service before
 * 2.3); its result lands in status.json.
 */
final class BackupNow
{
    /**
     * How the helper is run, and how the running state is read; tests point them at stand-ins.
     *
     * @var list<string>|null
     */
    public static ?array $command = null;

    /** @var list<string> */
    public static array $isActive = ['systemctl', 'is-active', '--quiet', 'radiusone-backup-now.service', 'frweb-backup-now.service'];

    /** @return list<string> */
    private static function command(): array
    {
        return self::$command ?? ['sudo', '-n', Paths::helper('backup-now')];
    }

    /** Whether the root helper is installed (ops/radiusone-postinstall). */
    public static function ready(): bool
    {
        $command = self::command();

        return is_executable((string) end($command));
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
            throw new RuntimeException('Manual backups are not enabled on this server. Run ops/radiusone-postinstall as root first.');
        }
        $result = BoundedProcess::run(self::command(), '', 30, 8192);
        if ($result['exit'] !== 0) {
            $detail = trim($result['error']) ?: trim($result['output']);
            if (str_contains($detail, 'password is required') || str_contains($detail, 'not allowed')) {
                $detail = 'www-data is not allowed to start backups. Run ops/radiusone-postinstall as root.';
            }
            throw new RuntimeException($detail !== '' ? $detail : 'The backup could not be started.');
        }
    }
}
