<?php
declare(strict_types=1);
namespace App\Support;
use DateTimeImmutable;
use RuntimeException;
final class BillingCalendar
{
    public static function months(string $period): int
    {
        return match ($period) { 'monthly'=>1, '2_months'=>2, '3_months'=>3, '6_months'=>6, 'yearly'=>12, default=>throw new RuntimeException('Invalid billing period.') };
    }
    public static function date(string $date): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) { throw new RuntimeException('Invalid billing date.'); }
        return $day;
    }
    /** Inclusive period end: the day before the next clamped monthly anchor. */
    public static function end(string $start, string $period, ?int $anchor = null): string
    {
        $day = self::date($start);
        $anchor ??= (int) $day->format('d');
        if ($anchor < 1 || $anchor > 31) { throw new RuntimeException('Invalid billing anchor.'); }
        $month = $day->modify('first day of this month')->modify('+'.self::months($period).' months');
        return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min($anchor,(int)$month->format('t')))->modify('-1 day')->format('Y-m-d');
    }
}
