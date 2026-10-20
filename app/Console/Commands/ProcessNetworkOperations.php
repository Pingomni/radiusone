<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\App;
use App\Support\CertifiedNetworkDriver;
use App\Support\NetworkOperations;
use App\Support\SchemaCompatibility;
use RuntimeException;
use Throwable;

final class ProcessNetworkOperations extends FrwebCommand
{
    protected $signature = 'frweb:process-network-operations';
    protected $description = 'Apply queued network operations (needs NETWORK_WORKER_ENABLED=true)';

    public function handle(): int
    {
        try {
            SchemaCompatibility::require('policy');
            if (!filter_var(App::env('NETWORK_WORKER_ENABLED', 'false'), FILTER_VALIDATE_BOOL)) {
                throw new RuntimeException('Network worker disabled; deployment and device acceptance are required.');
            }
            $this->json(NetworkOperations::run(new CertifiedNetworkDriver));
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Network worker stopped', $e);
        }
    }
}
