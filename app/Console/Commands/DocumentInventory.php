<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Documents;

final class DocumentInventory extends RadiusOneCommand
{
    protected $signature = 'radiusone:document-inventory';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:document-inventory'];
    protected $description = 'Compare stored customer documents with their database records';

    public function handle(): int
    {
        $this->json(Documents::inventory(), true);
        return self::SUCCESS;
    }
}
