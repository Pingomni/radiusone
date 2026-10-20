<?php

declare(strict_types=1);

namespace App\Support\Policy;

final class RouterOsDhcp extends Adapter
{
    public function key(): string
    {
        return 'routeros_dhcp';
    }
    public function replies(array $plan, array $identity): array
    {
        $attrs = $this->addressing($plan, $identity);
        [$down,$up] = $this->rates($plan);
        if ($down !== null) {
            $attrs[] = ['Mikrotik-Rate-Limit', ':=', $up . '/' . $down];
        }
        return $attrs;
    }
}
