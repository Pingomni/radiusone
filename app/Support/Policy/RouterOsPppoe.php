<?php
declare(strict_types=1);
namespace App\Support\Policy;
final class RouterOsPppoe extends Adapter
{
    public function key(): string{return 'routeros_pppoe';}
    public function replies(array $plan,array $identity): array
    {
        $attrs=$this->ppp($plan,$identity);[$down,$up]=$this->rates($plan);
        // NAS receive = customer upload; NAS transmit = customer download.
        if($down!==null){$attrs[]=['Mikrotik-Rate-Limit',':=',$up.'/'.$down];}
        return $attrs;
    }
}
