<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Support\Format;
use InvalidArgumentException;

/**
 * The one currency the portal bills in (Settings → Regional). Service plans, static IP pools and new
 * invoices all use it. Changing it relabels plan and pool prices and draft invoices (amounts are not
 * converted); issued invoices and payments keep the currency they were issued in.
 */
final class Currency
{
    public const OPTIONS = ['EUR' => 'Euro (€)', 'USD' => 'US dollar ($)', 'ALL' => 'Albanian lek (L)'];
    private const KEY = 'billing_currency';

    /** The system currency; before one is chosen, the currency most service plans already use. */
    public static function code(): string
    {
        $code = Settings::value(self::KEY, '');
        if (!isset(self::OPTIONS[$code])) {
            try {
                $code = (string) Db::value('SELECT currency FROM service_metadata GROUP BY currency ORDER BY COUNT(*) DESC, currency LIMIT 1');
            } catch (\Throwable) {
                $code = '';
            }
        }
        return isset(self::OPTIONS[$code]) ? $code : 'USD';
    }

    /**
     * Makes $code the system currency.
     * @return array{services:int,pools:int,drafts:int} rows relabelled
     */
    public static function change(string $code): array
    {
        $code = strtoupper(trim($code));
        if (!isset(self::OPTIONS[$code])) {
            throw new InvalidArgumentException('Choose Euro, US dollar or Albanian lek.');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $counts = [
                'services' => Db::exec('UPDATE service_metadata SET currency=? WHERE currency<>?', [$code, $code]),
                'pools' => Db::exec('UPDATE static_ip_pools SET currency=?, updated_at=NOW() WHERE currency<>?', [$code, $code]),
                'drafts' => Db::exec('UPDATE billing_invoices SET currency=?, updated_at=NOW() WHERE status="draft" AND currency<>?', [$code, $code]),
            ];
            Settings::save(self::KEY, $code);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $counts;
    }

    /** "1,200.00 ALL" */
    public static function amount(mixed $value, ?string $code = null): string
    {
        return Format::money($value) . ' ' . ($code ?? self::code());
    }

    /**
     * Sums that may span currencies (older invoices can predate a currency change), e.g.
     * "120.00 EUR + 30.00 USD". Rows: [currency => amount]; zero amounts are left out.
     */
    public static function totals(array $byCurrency): string
    {
        $parts = [];
        foreach ($byCurrency as $code => $amount) {
            if ((float) $amount != 0.0) {
                $parts[] = self::amount($amount, (string) $code);
            }
        }
        return $parts ? implode(' + ', $parts) : self::amount(0);
    }
}
