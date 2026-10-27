<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DatabaseBackup;
use Throwable;

final class Restore extends RadiusOneCommand
{
    protected $signature = 'radiusone:restore {file : Path to a .sql.gz backup}';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:restore'];
    protected $description = 'Restore the database from a backup file';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!is_file($file)) {
            $this->getOutput()->getErrorStyle()->writeln('Usage: php artisan radiusone:restore /path/to/backup.sql.gz');
            return self::FAILURE;
        }
        try {
            DatabaseBackup::restore($file, DatabaseBackup::settings());
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Restore failed', $e, true);
        }
    }
}
