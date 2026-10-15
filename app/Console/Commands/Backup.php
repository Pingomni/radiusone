<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\App;
use App\Support\Backup\BackupRunner;
use App\Support\DatabaseBackup;

final class Backup extends FrwebCommand
{
    protected $signature = 'frweb:backup {--no-upload : Only write the local copies}';
    // Run with FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/backup.env: the portal identity cannot dump.
    protected $description = 'Back up the database and customer documents; upload encrypted copies to the destinations in Settings → Backups';

    public function handle(): int
    {
        $status = BackupRunner::run(rtrim((string) App::env('BACKUP_PATH', App::root('var/backups')), '/'), DatabaseBackup::settings(), !$this->option('no-upload'));
        $this->json($status, true);
        if (!$status['ok']) {
            $this->getOutput()->getErrorStyle()->writeln('Backup incomplete: ' . ($status['error'] ?? 'a destination failed; see above.'));
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
