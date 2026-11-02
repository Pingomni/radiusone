<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Firewall;

/** Settings → Firewall from the server's side: sync (scheduler), apply and off (root, ops/radiusone-firewall). */
final class FirewallCommand extends RadiusOneCommand
{
    protected $signature = 'radiusone:firewall {action : sync, apply or off}';

    protected $description = 'Keep the firewall rules in force up to date, or (as root) apply them or turn them off';

    public function handle(): int
    {
        $action = $this->argument('action');
        if ($action === 'sync') {
            $this->line(Firewall::sync());

            return self::SUCCESS;
        }
        if (!in_array($action, ['apply', 'off'], true)) {
            $this->error('Use sync, apply or off.');

            return self::INVALID;
        }
        if (posix_geteuid() !== 0) {
            $this->error('radiusone:firewall ' . $action . ' runs as root: use sudo radiusone-firewall ' . $action . '.');

            return self::FAILURE;
        }

        return $action === 'apply' ? Firewall::rootApply() : Firewall::rootOff();
    }
}
