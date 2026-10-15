<?php
declare(strict_types=1);
namespace App\Support;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
final class Entitlement
{
    public static function today(?int $now = null): string
    {
        return (new DateTimeImmutable('@' . ($now ?? time())))
            ->setTimezone(new DateTimeZone((string)App::env('BUSINESS_TIMEZONE','Europe/Tirane')))->format('Y-m-d');
    }
    public static function expiryInstant(string $date): int
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone((string)App::env('BUSINESS_TIMEZONE','Europe/Tirane')));
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
