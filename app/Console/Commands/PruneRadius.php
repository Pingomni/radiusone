<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Retention;
use Throwable;

final class PruneRadius extends FrwebCommand
{
    protected $signature = 'frweb:prune-radius {--max-seconds=600 : Stop after this long; the rest is deleted the next night}';
    // Needs DELETE on radacct and radpostauth (ops/install/grants.sql).
    protected $description = 'Delete session and login records older than the periods chosen under Settings → Data retention';

    public function handle(): int
    {
        try {
            $this->json(Retention::prune(max(10, (int) $this->option('max-seconds'))));
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('RADIUS record cleanup failed', $e);
        }
    }
}
