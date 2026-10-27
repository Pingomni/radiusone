<?php

declare(strict_types=1);

namespace App\Support\Backup;

/** A remote place for encrypted backups. Only files named "radiusone-…" (or "frweb-…", before 2.3) are listed or deleted. */
interface BackupTarget
{
    /** Remote backup names start with one of these: radiusone- from 2.3, frweb- before. */
    public const PREFIXES = ['radiusone-', 'frweb-'];

    public function put(string $file, string $name): void;

    /** @return array<string, string> file name => id used by delete() */
    public function list(): array;

    public function delete(string $id): void;
}
