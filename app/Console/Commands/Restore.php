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
        // Run as root, restore only a file root owns, in a folder no one else can write: never a backup another user
        // (www-data) could have replaced.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $own = lstat($file);
            $dir = stat(dirname((string) realpath($file)));
            if (is_link($file) || $own === false || $dir === false || $own['uid'] !== 0 || $dir['uid'] !== 0 || ($dir['mode'] & 0022) !== 0) {
                $this->getOutput()->getErrorStyle()->writeln('Refusing ' . $file . ': restore a root-owned backup from a folder only root can write (copy it to /root first).');
                return self::FAILURE;
            }
        }
        try {
            DatabaseBackup::restore($file, DatabaseBackup::settings());
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Restore failed', $e, true);
        }
    }
}
