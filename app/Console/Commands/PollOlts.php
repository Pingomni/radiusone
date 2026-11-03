<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Db;
use App\Support\Olt\OltSync;
use Throwable;

final class PollOlts extends RadiusOneCommand
{
    protected $signature = 'radiusone:poll-olts';
    protected $description = 'Read ONUs from the OLTs that are due, and link clients to them. Never changes an OLT';

    public function handle(): int
    {
        try {
            // One at a time: a slow OLT can take longer than the minute between runs.
            $lock = 'radiusone:poll-olts:' . substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 30);
            if ((int) Db::value('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
                $this->json(['state' => 'skipped']);
                return self::BUSY;
            }
            try {
                $summary = OltSync::due();
            } finally {
                Db::value('SELECT RELEASE_LOCK(?)', [$lock]);
            }
            $this->json($summary);
            return $summary['failed'] > 0 ? 2 : self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('OLT sync failed', $e);
        }
    }
}
