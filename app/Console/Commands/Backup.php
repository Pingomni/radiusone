<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\App;
use App\Support\Backup\BackupRunner;
use App\Support\DatabaseBackup;

final class Backup extends RadiusOneCommand
{
    protected $signature = 'radiusone:backup {--no-upload : Only write the local copies}';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:backup'];
    // Run with RADIUSONE_OPERATOR_ENV_FILE=/etc/radiusone/backup.env: the portal identity cannot dump.
    protected $description = 'Back up the database and customer documents; upload encrypted copies to the destinations in Settings → Backups';

    public function handle(): int
    {
        $dir = rtrim((string) App::env('BACKUP_PATH', App::root('var/backups')), '/');
        // The nightly run and "Back up now" must not write the same files at once.
        @mkdir($dir, 0750, true);
        $lock = @fopen($dir . '/.backup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->line('Another backup is still running.');
            return self::BUSY;
        }
        try {
            $status = BackupRunner::run($dir, DatabaseBackup::settings(), !$this->option('no-upload'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->json($status, true);
        if (!$status['ok']) {
            $this->getOutput()->getErrorStyle()->writeln('Backup incomplete: ' . ($status['error'] ?? 'a destination failed; see above.'));
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
