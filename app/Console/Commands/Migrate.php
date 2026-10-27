<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Db;
use App\Support\MigrationRunner;
use RuntimeException;
use Throwable;

final class Migrate extends RadiusOneCommand
{
    protected $signature = 'radiusone:migrate
        {--baseline-through= : Record migrations up to this one as applied without running them}
        {--confirm-schema-reviewed : Required with --baseline-through}';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:migrate'];
    // Run with RADIUSONE_OPERATOR_ENV_FILE=/etc/radiusone/migration.env (the portal identity cannot ALTER).
    protected $description = 'Apply pending database/sql migrations (same ledger as the legacy bin/migrate.php)';

    public function handle(): int
    {
        try {
            foreach (['radcheck', 'radreply', 'radusergroup', 'radgroupcheck', 'radgroupreply', 'radacct', 'nas'] as $table) {
                if (!Db::value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])) {
                    throw new RuntimeException('Missing FreeRADIUS table: ' . $table);
                }
            }
            $baseline = $this->option('baseline-through');
            if ($baseline !== null && !$this->option('confirm-schema-reviewed')) {
                throw new RuntimeException('Baseline adoption requires --confirm-schema-reviewed after comparing schema and migration effects.');
            }
            $this->json(MigrationRunner::run(base_path('database/sql'), $baseline));
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Migration failed', $e, true);
        }
    }
}
