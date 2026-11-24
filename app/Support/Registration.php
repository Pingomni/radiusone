<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use Throwable;

/**
 * Registering this server: every RadiusOne server needs a licence key from the PingOmni account portal
 * (account.pingomni.com), also RadiusOne Community, whose key is free (plan Community, price 0, a year at a
 * time, renewed for free). Only the web console asks for it: RADIUS, the network queue, scheduled jobs and
 * the health check never look here, so a missing key never takes a subscriber offline.
 *
 * A server is registered while its key is correctly signed and valid, or expired less than GRACE_DAYS ago.
 * A server without a registration gets GRACE_DAYS from the first time this check finds it so: that moment is
 * stored in the setting GRACE_KEY and never cleared, so the grace is given once. While it lasts the console
 * shows a banner on every page; once it is over the console opens only the "Register this server" page
 * (RequireRegistration) until a key is saved.
 *
 * For an expired key the days after its expiry are its grace: the check stores the expiry date as the grace
 * start when it sees the key in that window, so the console locks GRACE_DAYS after the expiry, not later.
 * A server that never ran this check while its key was in that window (one upgraded with a key that expired
 * long ago) gets GRACE_DAYS from now, like any server already running.
 *
 * @phpstan-type Status array{state: string, reason: string, days_left: int|null, expires: string|null}
 */
final class Registration
{
    public const GRACE_DAYS = 30;

    public const GRACE_KEY = 'registration_grace_started';

    /** Where a free RadiusOne Community key is issued, or renewed. */
    public const URL = 'https://account.pingomni.com/buy?product=radiusone&plan=Community&period=12';

    /**
     * Whether this server is registered, and if not, how long the grace has left.
     *
     * state registered | grace | locked; reason none | invalid | expired (why it is not, or will soon not be,
     * registered; none while registered with a key that has not expired); days_left: the days until the
     * console locks, null while registered with a key that has not expired.
     *
     * @return Status
     */
    public static function status(): array
    {
        $licence = License::current();
        $today = new DateTimeImmutable('today');
        $expires = isset($licence['expires']) ? (string) $licence['expires'] : null;

        if ($licence['state'] === 'active') {
            return ['state' => 'registered', 'reason' => 'none', 'days_left' => null, 'expires' => $expires];
        }

        $start = self::graceStart();
        if ($licence['state'] === 'expired' && $expires !== null) {
            $left = self::GRACE_DAYS - self::daysSince(new DateTimeImmutable($expires), $today);
            if ($left > 0) {
                // Still registered: the days after the expiry are the grace, recorded once.
                if ($start === null) {
                    self::storeGraceStart($expires);
                }

                return ['state' => 'registered', 'reason' => 'expired', 'days_left' => $left, 'expires' => $expires];
            }
        }

        if ($start === null) {
            $start = $today;
            self::storeGraceStart($today->format('Y-m-d'));
        }
        $left = self::GRACE_DAYS - self::daysSince($start, $today);
        $reason = $licence['state'] === 'none' ? 'none' : $licence['state'];

        return ['state' => $left > 0 ? 'grace' : 'locked', 'reason' => $reason, 'days_left' => max($left, 0), 'expires' => $expires];
    }

    /** Whether the console may only open the registration page now. Never throws: an error never locks the console. */
    public static function locked(): bool
    {
        try {
            return self::status()['state'] === 'locked';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The banner the console shows on every page while the server is in its grace, or its key in the days
     * after its expiry; null when there is nothing to say.
     *
     * @return array{reason: string, days_left: int, url: string}|null
     */
    public static function banner(): ?array
    {
        try {
            $status = self::status();
        } catch (Throwable) {
            return null;
        }
        if ($status['days_left'] === null || $status['state'] === 'locked') {
            return null;
        }

        return ['reason' => $status['reason'], 'days_left' => $status['days_left'], 'url' => self::URL];
    }

    private static function graceStart(): ?DateTimeImmutable
    {
        $value = Settings::value(self::GRACE_KEY, '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private static function storeGraceStart(string $date): void
    {
        Settings::save(self::GRACE_KEY, $date);
    }

    private static function daysSince(DateTimeImmutable $from, DateTimeImmutable $today): int
    {
        return (int) $from->diff($today)->format('%r%a');
    }
}
