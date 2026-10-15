<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\App;
use App\Support\BoundedProcess;
use App\Support\DatabaseBackup;
use RuntimeException;
use Throwable;

/**
 * The nightly backup (artisan frweb:backup, root cron with the read-only backup identity): database
 * dump and customer documents archive, both encrypted with the backup passphrase and uploaded to every
 * enabled destination; older copies are then removed remotely (per destination, only after its upload
 * succeeded) and locally. The result goes to status.json in the backup folder, which Settings → Backups
 * shows (the backup identity cannot write to the database).
 */
final class BackupRunner
{
    public const STATUS = 'status.json';

    /** @return array<string, mixed> the status that was written */
    public static function run(string $dir, array $db, bool $upload = true): array
    {
        $status = ['at' => gmdate('Y-m-d H:i:s'), 'ok' => true, 'destinations' => []];
        $staged = [];
        try {
            $dbFile = DatabaseBackup::backup($dir, $db);
            $status['database'] = ['file' => basename($dbFile), 'size' => (int) filesize($dbFile)];
            $docsFile = self::documents($dir);
            $status['documents'] = ['file' => basename($docsFile), 'size' => (int) filesize($docsFile)];

            $destinations = array_values(array_filter(BackupDestinations::all(), static fn ($d) => !empty($d['enabled'])));
            if ($upload && $destinations) {
                $passphrase = BackupDestinations::passphrase();
                if ($passphrase === '') {
                    throw new RuntimeException('Set a backup passphrase under Settings → Backups; nothing is uploaded unencrypted.');
                }
                $stamp = preg_replace('/^freeradius-web-|\.sql\.gz$/', '', basename($dbFile));
                $staged = [
                    'frweb-db-' . $stamp . '.sql.gz' . BackupCrypto::EXTENSION => $dbFile,
                    'frweb-docs-' . $stamp . '.tar.gz' . BackupCrypto::EXTENSION => $docsFile,
                ];
                foreach ($staged as $name => $source) {
                    BackupCrypto::encrypt($source, $dir . '/.' . $name, $passphrase);
                }
                foreach ($destinations as $destination) {
                    $status['destinations'][$destination['id']] = self::upload($destination, $staged, $dir);
                    $status['ok'] = $status['ok'] && $status['destinations'][$destination['id']]['ok'];
                }
            }
            $status['local_removed'] = self::pruneLocal($dir, BackupDestinations::localDays());
        } catch (Throwable $e) {
            $status['ok'] = false;
            $status['error'] = $e instanceof RuntimeException ? $e->getMessage() : get_class($e);
        } finally {
            foreach (array_keys($staged) as $name) {
                @unlink($dir . '/.' . $name);
            }
        }
        $old = umask(0022);
        file_put_contents($dir . '/' . self::STATUS, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        umask($old);
        return $status;
    }

    /** @return array{name: string, type: string, ok: bool, message: string, removed: int} */
    private static function upload(array $destination, array $staged, string $dir): array
    {
        $result = ['name' => (string) $destination['name'], 'type' => (string) $destination['type'], 'ok' => false, 'message' => '', 'removed' => 0];
        try {
            $target = BackupDestinations::target($destination);
            foreach (array_keys($staged) as $name) {
                $target->put($dir . '/.' . $name, $name);
            }
            $result['removed'] = self::pruneRemote($target, (int) $destination['keep']);
            $result['ok'] = true;
            $result['message'] = 'Uploaded ' . count($staged) . ' files.';
        } catch (Throwable $e) {
            $result['message'] = $e instanceof RuntimeException ? $e->getMessage() : get_class($e);
        }
        return $result;
    }

    /** Keeps the newest $keep copies of each kind (names sort by date); returns how many were removed. */
    private static function pruneRemote(BackupTarget $target, int $keep): int
    {
        $removed = 0;
        $files = $target->list();
        foreach (['frweb-db-', 'frweb-docs-'] as $kind) {
            $names = array_values(array_filter(array_keys($files), static fn ($n) => str_starts_with($n, $kind) && str_ends_with($n, BackupCrypto::EXTENSION)));
            rsort($names);
            foreach (array_slice($names, max(1, $keep)) as $name) {
                $target->delete($files[$name]);
                $removed++;
            }
        }
        return $removed;
    }

    /** Local copies older than $days are removed, but the newest three of each kind always stay. */
    private static function pruneLocal(string $dir, int $days): int
    {
        $removed = 0;
        foreach (['freeradius-web-*.sql.gz', 'documents-*.tar.gz'] as $pattern) {
            $files = glob($dir . '/' . $pattern) ?: [];
            usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
            foreach (array_slice($files, 3) as $file) {
                if (filemtime($file) < time() - $days * 86400 && unlink($file)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    private static function documents(string $dir): string
    {
        $source = rtrim((string) App::env('DOCUMENT_STORAGE', '/var/lib/freeradius-web/documents'), '/');
        $file = $dir . '/documents-' . gmdate('Ymd-His') . '.tar.gz';
        $args = is_dir($source) ? ['-C', dirname($source), basename($source)] : ['--files-from', '/dev/null'];
        $result = BoundedProcess::run(['tar', '-czf', $file, ...$args], '', 600);
        if ($result['exit'] !== 0) {
            @unlink($file);
            throw new RuntimeException('Documents archive failed: ' . mb_substr(trim($result['error']), 0, 200));
        }
        chmod($file, 0640);
        return $file;
    }

    /** The last nightly result, for Settings → Backups. */
    public static function lastStatus(string $dir): ?array
    {
        $status = is_readable($dir . '/' . self::STATUS) ? json_decode((string) file_get_contents($dir . '/' . self::STATUS), true) : null;
        return is_array($status) ? $status : null;
    }
}
