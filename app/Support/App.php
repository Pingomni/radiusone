<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Compatibility facade for the domain classes ported from the plain-PHP portal.
 * Values come from Laravel's environment (including App\Support\SharedEnv).
 */
final class App
{
    /** Legacy names that map to a different key in this app. */
    private const ALIASES = [
        'APP_KEY' => 'FRWEB_SECRETS_KEY',
        'APP_KEY_PREVIOUS' => 'FRWEB_SECRETS_KEY_PREVIOUS',
    ];

    public static function env(string $key, mixed $default = null): mixed
    {
        $value = env(self::ALIASES[$key] ?? $key);
        return $value === null || $value === '' ? $default : $value;
    }

    public static function root(string $path = ''): string
    {
        return base_path($path);
    }

    public static function isDebug(): bool
    {
        return (bool) config('app.debug');
    }
}
