<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Settings → Regional → Time zone. The server's zone is the one everything uses (config/app.php);
 * changing it goes through the root helper ops/frweb-set-timezone, which also converts stored times.
 */
final class TimeZone
{
    public const HELPER = '/usr/local/sbin/frweb-set-timezone';
    public const LOG = '/var/log/freeradius-web/timezone.log';
    /** How the helper is run; tests point it at a stand-in script. */
    public static array $command = ['sudo', '-n', self::HELPER];

    public static function current(): string
    {
        return date_default_timezone_get();
    }

    /** @return array<string, string> zone => "Europe/Tirane (UTC+02:00)", grouped by region in name order */
    public static function options(): array
    {
        $now = new \DateTimeImmutable('now');
        $options = [];
        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $options[$zone] = str_replace('_', ' ', $zone) . ' (UTC' . $now->setTimezone(new DateTimeZone($zone))->format('P') . ')';
        }
        return $options;
    }

    /** Asks the root helper to switch zones; it starts a few seconds later and puts the portal in maintenance for about a minute. */
    public static function change(string $zone): void
    {
        if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Choose a time zone from the list.');
        }
        if ($zone === self::current()) {
            throw new InvalidArgumentException("The server already uses $zone.");
        }
        if (!self::ready()) {
            throw new RuntimeException('Time zone changes are not enabled on this server. Run ops/apply-service-control.sh as root first.');
        }
        $result = BoundedProcess::run([...self::$command, 'schedule', $zone], '', 30, 8192);
        if ($result['exit'] !== 0) {
            $detail = trim($result['error']) ?: trim($result['output']);
            if (str_contains($detail, 'password is required') || str_contains($detail, 'not allowed')) {
                $detail = 'www-data is not allowed to run the time zone helper. Run ops/apply-service-control.sh as root.';
            }
            throw new RuntimeException($detail !== '' ? $detail : 'The time zone change could not be started.');
        }
    }

    /** Whether the root helper is installed (ops/apply-service-control.sh). */
    public static function ready(): bool
    {
        return is_executable((string) end(self::$command));
    }

    /** @return list<string> the last lines the helper logged (newest last) */
    public static function log(int $lines = 8): array
    {
        $text = is_readable(self::LOG) ? (string) @file_get_contents(self::LOG, false, null, max(0, (int) @filesize(self::LOG) - 8192)) : '';
        $rows = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn ($l) => $l !== ''));
        return array_slice($rows, -$lines);
    }
}
