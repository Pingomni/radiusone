<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Olt\OltConsole;
use Throwable;

/** The service behind the OLT's live console (radiusone-olt-console.service). Changes nothing by itself. */
final class OltConsoleCommand extends RadiusOneCommand
{
    protected $signature = 'radiusone:olt-console';
    protected $description = 'Hold the OLT console sessions operators open from the portal (runs as a service)';

    public function handle(): int
    {
        try {
            $this->line('Listening on ' . OltConsole::socketPath());
            (new OltConsole)->serve();
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('OLT console service failed', $e, true);
        }
    }
}
