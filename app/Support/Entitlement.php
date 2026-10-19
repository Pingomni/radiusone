<?php
declare(strict_types=1);
namespace App\Support;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
final class Entitlement
{
    /** The business day follows the server time zone unless BUSINESS_TIMEZONE is set. */
    private static function zone(): string
    {
        return (string) (App::env('BUSINESS_TIMEZONE') ?: date_default_timezone_get());
    }
    public static function today(?int $now = null): string
    {
        return (new DateTimeImmutable('@' . ($now ?? time())))
            ->setTimezone(new DateTimeZone(self::zone()))->format('Y-m-d');
    }
    public static function expiryInstant(string $date): int
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::zone()));
        if (!$day || $day->format('Y-m-d') !== $date) { throw new RuntimeException('Invalid expiry date.'); }
        // Epoch values avoid depending on the RADIUS daemon's local timezone.
        return $day->modify('+1 day')->getTimestamp();
    }
    public static function isExpired(array $client, ?int $now = null): bool
    {
        $date = trim((string) ($client['expiry_date'] ?? ''));
        return $date !== '' && ($now ?? time()) >= self::expiryInstant($date);
    }
}
