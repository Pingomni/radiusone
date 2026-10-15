<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SchemaMismatch;
use Illuminate\Console\Command;
use Throwable;

/** Shared output for the frweb:* operator commands (ported from the legacy bin/*.php scripts). */
abstract class FrwebCommand extends Command
{
    /** Exit code when another run of the same job holds its lock (cron treats it as "try later"). */
    public const BUSY = 75;

    protected function json(mixed $value, bool $pretty = false): void
    {
        $this->line(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0)));
    }

    /** Writes "$prefix: reason" to stderr; only schema problems (or $verbose errors) show their message. */
    protected function failure(string $prefix, Throwable $error, bool $verbose = false, int $code = self::FAILURE): int
    {
        $reason = $verbose || $error instanceof SchemaMismatch ? $error->getMessage() : get_class($error);
        $this->getOutput()->getErrorStyle()->writeln($prefix . ': ' . $reason);
        return $code;
    }

    /** Result of JobRunner::run(): printed as JSON; a skipped run exits BUSY. */
    protected function jobResult(array $result): int
    {
        $this->json($result);
        return $result['state'] === 'skipped' ? self::BUSY : self::SUCCESS;
    }
}
