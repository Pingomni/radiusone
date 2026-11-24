<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Activation;
use App\Support\Registration;
use Throwable;

/** Registers the licence's server with the PingOmni account, or checks in, when due (Activation), and notes an unregistered server's grace (Registration). */
final class LicenseCheckIn extends RadiusOneCommand
{
    protected $signature = 'license:check-in {--force : Register or check in now, even if not due}';

    protected $description = 'Register this server with the PingOmni account (report only), or check in';

    public function handle(): int
    {
        try {
            Activation::run((bool) $this->option('force'));
            // Starts the registration grace on a server without a key even if nobody opens the console. It never
            // stops anything here: only the web console asks for a key.
            Registration::status();
            $this->json(Activation::public());

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Licence registration skipped', $e);
        }
    }
}
