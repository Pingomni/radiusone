<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class DatabaseBackup
{
    public static function settings(): array
    {
        $settings = [];
        foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE'] as $key) {
            $settings[$key] = (string) App::env($key, match ($key) {
                'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', default => ''
            });
        }
        return $settings;
    }

    public static function backup(string $directory, array $settings): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new RuntimeException('Could not create backup directory.');
        }
        $defaults = self::defaults($settings);
        $sql = null;
        $compressed = null;
        try {
            $sql = self::temporary($directory);
            $compressed = self::temporary($directory);
            self::run(['mysqldump', '--defaults-file=' . $defaults, '--single-transaction', '--skip-routines', '--triggers', $settings['DB_DATABASE']], '/dev/null', $sql, 'Database dump');
            self::run(['gzip', '-c', $sql], '/dev/null', $compressed, 'Compression');
            self::run(['gzip', '-t', $compressed], '/dev/null', '/dev/null', 'Backup integrity check');
            $file = rtrim($directory, '/') . '/radiusone-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
            if (!rename($compressed, $file)) {
                throw new RuntimeException('Could not publish backup.');
            }
            return $file;
        } finally {
            foreach ([$defaults, $sql, $compressed] as $file) {
                if ($file !== null && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public static function restore(string $file, array $settings): void
    {
        if (!is_file($file)) {
            throw new RuntimeException('Backup file does not exist.');
        }
        $defaults = self::defaults($settings);
        $sql = null;
        try {
            $sql = self::temporary(sys_get_temp_dir());
            // Complete decompression before allowing any SQL to reach the database.
            self::run(['gzip', '-dc', $file], '/dev/null', $sql, 'Decompression');
            self::run(['mysql', '--defaults-file=' . $defaults, $settings['DB_DATABASE']], $sql, '/dev/null', 'Database restore');
        } finally {
            foreach ([$defaults, $sql] as $path) {
                if ($path !== null && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private static function temporary(string $directory): string
    {
        $file = tempnam($directory, '.radiusone-');
        if ($file === false) {
            throw new RuntimeException('Could not create temporary file.');
        }
        if (realpath(dirname($file)) !== realpath($directory)) {
            unlink($file);
            throw new RuntimeException('Temporary file left the requested directory.');
        }
        if (!chmod($file, 0600)) {
            unlink($file);
            throw new RuntimeException('Could not protect temporary file.');
        }
        return $file;
    }

    private static function defaults(array $settings): string
    {
        $file = self::temporary(sys_get_temp_dir());
        try {
            $body = "[client]\n";
            foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'user' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'socket' => 'DB_SOCKET'] as $option => $key) {
                if (!isset($settings[$key])) {
                    continue;
                }
                $value = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $settings[$key]);
                $body .= $option . '="' . $value . '"' . "\n";
            }
            if (isset($settings['DB_SOCKET'])) {
                $body .= "protocol=SOCKET\n";
            }
            if (file_put_contents($file, $body) === false) {
                throw new RuntimeException('Could not write client options.');
            }
            return $file;
        } catch (\Throwable $e) {
            unlink($file);
            throw $e;
        }
    }

    private static function run(array $command, string $input, string $output, string $stage): void
    {
        $process = proc_open($command, [0 => ['file', $input, 'r'], 1 => ['file', $output, 'w'], 2 => STDERR], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException($stage . ' could not start.');
        }
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException($stage . ' failed (exit ' . $code . ').');
        }
    }
}
