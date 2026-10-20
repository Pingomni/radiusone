<?php

declare(strict_types=1);

namespace App\Support\Policy;

final class IosXePppoe extends Adapter
{
    public function key(): string
    {
        return 'iosxe_pppoe';
    }
    public function replies(array $plan, array $identity): array
    {
        return [...$this->ppp($plan, $identity), ...IosXeIpoe::qos($plan)];
    }
}
