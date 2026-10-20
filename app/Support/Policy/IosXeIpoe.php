<?php

declare(strict_types=1);

namespace App\Support\Policy;

final class IosXeIpoe extends Adapter
{
    public function key(): string
    {
        return 'iosxe_ipoe';
    }
    public function replies(array $plan, array $identity): array
    {
        // Local DHCP owns pool selection; do not emit PPP or RouterOS attributes.
        return [...$this->addressing($plan, $identity, false), ...self::qos($plan)];
    }
    public static function qos(array $plan): array
    {
        [$down,$up] = (new self)->rates($plan);
        if ($down === null) {
            return [];
        }
        // IOS XE ISG parameterized QoS requires existing parent policies in both directions.
        return [['Cisco-AVPair', '+=', 'qos-policy-in=add-class(sub,(class-default),police(' . $up . ',0,0,transmit,drop,drop))'],
            ['Cisco-AVPair', '+=', 'qos-policy-out=add-class(sub,(class-default),shape(' . $down . '))']];
    }
}
