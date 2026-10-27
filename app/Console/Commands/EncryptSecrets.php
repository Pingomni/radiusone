<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Secrets;
use Throwable;

/**
 * Encrypts stored portal-only credentials that are still plaintext (see App\Support\Secrets). Safe to rerun:
 * encrypted values are skipped. Prints counts only, never values.
 * Rotating the key: put the old key in RADIUSONE_SECRETS_KEY_PREVIOUS and the new one in RADIUSONE_SECRETS_KEY, run
 * this (values sealed with the old key are re-sealed, counted as "rekeyed"), then remove the previous key.
 * nas.secret (the RADIUS shared secret) is deliberately left alone: FreeRADIUS reads it directly.
 */
final class EncryptSecrets extends RadiusOneCommand
{
    protected $signature = 'radiusone:encrypt-secrets';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:encrypt-secrets'];
    protected $description = 'Encrypt or re-key stored credentials (exit 2 = some do not open with the current key)';

    public function handle(): int
    {
        try {
            $summary = Secrets::encryptStored();
            $this->json($summary);
            return array_sum(array_column($summary, 'unreadable')) > 0 ? 2 : self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Secret encryption failed, nothing changed', $e, true);
        }
    }
}
