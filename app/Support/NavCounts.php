<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Olt\Olts;
use Throwable;

/** The numbers beside the menu items: clients in total and ONUs with a weak signal. One cached read a minute. */
final class NavCounts
{
    public const CACHE_KEY = 'nav:counts';

    /** @return array{clients: int|null, weakOnus: int|null} null where the operator may not see that page */
    public static function forCurrentUser(): array
    {
        $all = MicroCache::remember(self::CACHE_KEY, 60, static function (): array {
            try {
                $weak = (int) Db::value('SELECT COUNT(*) FROM olt_onus o JOIN olts t ON t.id = o.olt_id
                    WHERE t.enabled = 1 AND ' . Olts::weakSql('o', 't.rx_warning'));
            } catch (Throwable) {
                $weak = 0;
            }
            return [
                'clients' => (int) Db::value('SELECT COUNT(*) FROM customer_profiles WHERE account_status <> "archived"'),
                'weakOnus' => $weak,
            ];
        });

        return [
            'clients' => Auth::can('clients.view') ? $all['clients'] : null,
            'weakOnus' => Auth::can('nas.view') ? $all['weakOnus'] : null,
        ];
    }
}
