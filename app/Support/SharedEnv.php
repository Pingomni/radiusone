<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Reads secrets from the protected legacy env file (root:www-data 0640) instead of copying them
 * into this app's .env. Only allow-listed keys are taken, and only when .env does not set them.
 *
 * Operator CLIs may name an alternate credential file in FRWEB_OPERATOR_ENV_FILE (migration or
 * backup identity); its DB_* values replace the portal identity for that process only.
 */
final class SharedEnv
{
    /** Shared-file key => key in this app. */
    private const SHARED_KEYS = [
        'DB_PASSWORD' => 'DB_PASSWORD',
        'APP_KEY' => 'FRWEB_SECRETS_KEY',
        'APP_KEY_PREVIOUS' => 'FRWEB_SECRETS_KEY_PREVIOUS',
    ];

    private const OPERATOR_KEYS = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    public static function load(): void
    {
        $shared = self::get('FRWEB_SHARED_ENV');
        if ($shared !== null && $shared !== '' && is_file($shared) && is_readable($shared)) {
            $values = self::parse($shared);
            foreach (self::SHARED_KEYS as $from => $to) {
                if (isset($values[$from]) && (self::get($to) ?? '') === '') {
                    self::set($to, $values[$from]);
                }
            }
        }

        $operator = PHP_SAPI === 'cli' ? getenv('FRWEB_OPERATOR_ENV_FILE') : false;
        if ($operator) {
            if (!str_starts_with($operator, '/') || !is_file($operator) || !is_readable($operator)) {
                throw new RuntimeException('Operator credential file must be an absolute readable file.');
            }
            $values = self::parse($operator);
            foreach (self::OPERATOR_KEYS as $key) {
                if (isset($values[$key])) {
                    self::set($key, $values[$key]);
                }
            }
        }
    }

    /** @return array<string, string> */
    public static function parse(string $path): array
    {
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }
            $values[trim($key)] = $value;
        }
        return $values;
    }

    private static function get(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return $value === false || $value === null ? null : (string) $value;
    }

    private static function set(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}
