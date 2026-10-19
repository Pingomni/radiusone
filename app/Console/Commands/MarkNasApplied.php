<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RadiusRepository;
use RuntimeException;
use Throwable;

final class MarkNasApplied extends FrwebCommand
{
    protected $signature = 'frweb:mark-nas-applied {--id=} {--revision=} {--evidence=} {--verified-activation} {--auto}';
    protected $description = 'Record a NAS configuration revision as in effect: --auto for every NAS saved before FreeRADIUS last started, or one verified revision (performs no network operation)';

    public function handle(): int
    {
        try {
            if ($this->option('auto')) {
                $this->json(['recorded' => RadiusRepository::recordLoadedNasRevisions()]);
                return self::SUCCESS;
            }
            if (!$this->option('verified-activation') || !$this->option('id') || !$this->option('revision') || !$this->option('evidence')) {
                throw new RuntimeException('Require --id, --revision, --evidence and --verified-activation after an independently verified deployment.');
            }
            RadiusRepository::markNasApplied((int) $this->option('id'), (int) $this->option('revision'), (string) $this->option('evidence'));
            $this->line('Verified NAS revision recorded. No activation or network operation was performed.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Not recorded', $e, true);
        }
    }
}
