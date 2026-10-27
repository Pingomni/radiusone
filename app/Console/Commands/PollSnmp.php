<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\NasTraffic;
use RuntimeException;
use Throwable;

final class PollSnmp extends RadiusOneCommand
{
    protected $signature = 'radiusone:poll-snmp';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:poll-snmp'];
    // "Poll now" on the Monitoring page runs the same recording.
    protected $description = 'Record RADIUS server and SNMP NAS samples for the monitoring graphs';

    public function handle(): int
    {
        try {
            $summary = NasTraffic::recordAll();
            $this->json($summary);
            return $summary['failed'] > 0 && $summary['polled'] === 0 ? 2 : self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('SNMP poll failed', $e, $e instanceof RuntimeException, str_contains($e->getMessage(), 'still running') ? self::BUSY : self::FAILURE);
        }
    }
}
