<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Backup\BackupCrypto;
use Throwable;

/** Turns a downloaded *.enc backup back into the .sql.gz / .tar.gz it came from (then: frweb:restore). */
final class BackupDecrypt extends FrwebCommand
{
    protected $signature = 'frweb:backup-decrypt {file : The downloaded .enc file} {--out= : Where to write (default: the same name without .enc)}';
    protected $description = 'Decrypt a backup downloaded from a remote destination (asks for the backup passphrase)';

    public function handle(): int
    {
        $file = is_string($this->argument('file')) ? $this->argument('file') : '';
        $option = $this->option('out');
        $out = is_string($option) && $option !== '' ? $option : (string) preg_replace('/\.enc$/', '', $file);
        if (!is_file($file) || $out === $file) {
            $this->getOutput()->getErrorStyle()->writeln('Give the path of a downloaded .enc backup.');
            return self::FAILURE;
        }
        // Prompted (never an argument, which would end up in the shell history); piped input also works.
        $passphrase = $this->input->isInteractive() ? (string) $this->secret('Backup passphrase') : rtrim((string) fgets(STDIN), "\r\n");
        try {
            BackupCrypto::decrypt($file, $out, $passphrase);
        } catch (Throwable $e) {
            return $this->failure('Not decrypted', $e, true);
        }
        $this->line($out);
        return self::SUCCESS;
    }
}
