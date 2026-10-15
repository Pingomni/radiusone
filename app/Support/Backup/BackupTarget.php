<?php

declare(strict_types=1);

namespace App\Support\Backup;

/** A remote place for encrypted backups. Only files named "frweb-…" are listed or deleted. */
interface BackupTarget
{
    public function put(string $file, string $name): void;

    /** @return array<string, string> file name => id used by delete() */
    public function list(): array;

    public function delete(string $id): void;
}
