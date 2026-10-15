<?php
declare(strict_types=1);
namespace App\Support\Policy;
abstract class Adapter
{
    abstract public function key(): string;
    abstract public function replies(array $plan, array $identity): array;
    public function isPpp(): bool {return str_ends_with($this->key(),'pppoe');}
    /** Decimal Mbps, or explicit K/M/G suffix. No independent vendor rate text. */
    public static function bps(mixed $speed): ?int
    {
        if($speed===null || trim((string)$speed)===''){return null;}
        if(!preg_match('/^([0-9]+(?:\.[0-9]{1,3})?)\s*(K|M|G|Mbps)?$/i',trim((string)$speed),$m)){
            throw new \RuntimeException('Use positive download/upload speeds in Mbps (or K/M/G).');
        }
        $factor=match(strtoupper($m[2]??'M')){'K'=>1000,'G'=>1000000000,default=>1000000};
        $rate=(int)round((float)$m[1]*$factor);
        if($rate<1000 || $rate>1000000000000){throw new \RuntimeException('Speed is outside the supported range.');}
        return $rate;
    }
    protected function addressing(array $plan,array $identity,bool $pool=true): array
    {
        $attrs=[];
        if(($identity['ip_mode']??'')==='static' && !empty($identity['framed_ip_address'])){$attrs[]=['Framed-IP-Address',':=',$identity['framed_ip_address']];}
        elseif($pool && ($plan['ip_mode']??'pool')==='pool' && ($name=($identity['framed_pool']??'')?:($plan['ip_pool']??''))){$attrs[]=['Framed-Pool',':=',$name];}
        return $attrs;
    }
    protected function ppp(array $plan,array $identity): array
    {
        $attrs=[['Framed-Protocol',':=','PPP'],['Service-Type',':=','Framed-User'],...$this->addressing($plan,$identity)];
        foreach(['session_timeout'=>'Session-Timeout','idle_timeout'=>'Idle-Timeout'] as $key=>$attribute){if(!empty($plan[$key])){$attrs[]=[$attribute,':=',(string)(int)$plan[$key]];}}
        return $attrs;
    }
    protected function rates(array $plan): array
    {
        $down=self::bps($plan['download_speed']??null);$up=self::bps($plan['upload_speed']??null);
        if(($down===null)!==($up===null)){throw new \RuntimeException('Supply both download and upload speeds.');}
        return [$down,$up];
    }
    /** Describes intent; all transports still require release-specific capability evidence. */
    public function operation(string $action,array $policy): array
    {
        if(!in_array($action,['apply','suspend','restore','disconnect'],true)){throw new \RuntimeException('Unknown network action.');}
        return ['adapter'=>$this->key(),'action'=>$action,'mechanism'=>$this->isPpp()?'disconnect_then_reauthenticate':'forwarding_policy_and_local_dhcp',
            'requires_forwarding_verification'=>!$this->isPpp(),'replies'=>$policy['replies']??[]];
    }
}
