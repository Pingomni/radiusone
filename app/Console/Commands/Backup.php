<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\App;
use App\Support\DatabaseBackup;
use Throwable;

final class Backup extends FrwebCommand
{
    protected $signature = 'frweb:backup';
    // Run with FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/backup.env: the portal identity cannot dump.
    protected $description = 'Write a compressed database backup to BACKUP_PATH';

    public function handle(): int
    {
        try {
            $this->line(DatabaseBackup::backup(rtrim((string) App::env('BACKUP_PATH', App::root('var/backups')), '/'), DatabaseBackup::settings()));
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Backup failed', $e, true);
        }
    }
}
