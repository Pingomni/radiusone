<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Activation;
use Throwable;

/** Registers the licence's server with the PingOmni account, or checks in, when due (Activation). */
final class LicenseCheckIn extends FrwebCommand
{
    protected $signature = 'license:check-in {--force : Register or check in now, even if not due}';

    protected $description = 'Register this server with the PingOmni account (report only), or check in';

    public function handle(): int
    {
        try {
            Activation::run((bool) $this->option('force'));
            $this->json(Activation::public());

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Licence registration skipped', $e);
        }
    }
}
