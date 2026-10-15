<?php
declare(strict_types=1);
namespace App\Support;
use RuntimeException;
final class StaticIpPool
{
    /** Strict, bounded IPv4 parser. Unsupported/invalid tokens never become truncated allocations. */
    public static function parse(string $text): array
    {
        $ips=[];
        foreach(preg_split('/\R/',$text) as $line){
            $line=trim(preg_replace('/#.*$/','',$line));
            $line=preg_replace('/\s*([-\/])\s*/','$1',$line);
            foreach(preg_split('/[,\s]+/',$line,-1,PREG_SPLIT_NO_EMPTY) as $part){
                if(filter_var($part,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){$addresses=[$part];}
                elseif(preg_match('/^(\d+\.\d+\.\d+\.)(\d+)-(\d+)$/D',$part,$m)){
                    $addresses=self::range($m[1].$m[2],$m[1].$m[3]);
                }elseif(preg_match('/^(.+)-(.+)$/D',$part,$m)){$addresses=self::range($m[1],$m[2]);}
                elseif(preg_match('/^(.+)\/(\d{1,2})$/D',$part,$m) && filter_var($m[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
                    $mask=(int)$m[2];if($mask<24 || $mask>32){throw new RuntimeException('Static pools support only /24 through /32.');}
                    $count=2**(32-$mask);$network=ip2long($m[1]) & (0xffffffff << (32-$mask));
                    $first=$mask>=31?0:1;$last=$mask>=31?$count-1:$count-2;$addresses=[];
                    for($i=$first;$i<=$last;$i++){$addresses[]=long2ip($network+$i);}
                }else{throw new RuntimeException('Invalid static pool token: '.$part);}
                foreach($addresses as $ip){$ips[$ip]=true;}
                if(count($ips)>4096){throw new RuntimeException('Static pool exceeds 4096 addresses.');}
            }
        }
        return array_keys($ips);
    }
    private static function range(string $start,string $end): array
    {
        if(!filter_var($start,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || !filter_var($end,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){throw new RuntimeException('Invalid static IP range.');}
        $a=ip2long($start);$b=ip2long($end);
        if($b<$a || $b-$a>=512){throw new RuntimeException('Static IP ranges must ascend and contain at most 512 addresses.');}
        $ips=[];for($i=$a;$i<=$b;$i++){$ips[]=long2ip($i);}return $ips;
    }
    public static function poolFor(string $ip,array $pools): ?array
    {
        $found=null;
        foreach($pools as $pool){if(in_array($ip,self::parse((string)$pool['ip_ranges']),true)){
            if($found!==null){throw new RuntimeException('Overlapping static pools require review.');}$found=$pool;
        }}
        return $found;
    }
    public static function save(array $input,?int $id=null): int
    {
        $name=trim((string)($input['name'] ?? ''));$key=trim((string)($input['pool_key'] ?? ''));
        $ranges=trim((string)($input['ip_ranges'] ?? ''));$ips=self::parse($ranges);
        if($name==='' || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$key) || !$ips){throw new RuntimeException('Valid pool name, key and ranges are required.');}
        $price=trim((string)($input['price'] ?? '0'));$currency=strtoupper((string)($input['currency'] ?? 'USD'));
        if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$price) || !preg_match('/^[A-Z]{3}$/D',$currency)){throw new RuntimeException('Invalid pool price or currency.');}
        $enabled=!empty($input['enabled'])?1:0;
        $pdo=Db::pdo();$pdo->beginTransaction();
        try{
            $pools=Db::select('SELECT * FROM static_ip_pools ORDER BY id FOR UPDATE');$before=null;
            foreach($pools as $pool){
                if((int)$pool['id']===$id){$before=$pool;continue;}
                if($enabled && $pool['enabled'] && array_intersect($ips,self::parse($pool['ip_ranges']))){throw new RuntimeException('Enabled static pools cannot overlap.');}
            }
            if($id && !$before){throw new RuntimeException('Pool not found.');}
            if($before){
                $removed=$enabled?array_diff(self::parse($before['ip_ranges']),$ips):self::parse($before['ip_ranges']);
                self::assertUnassigned($removed);
            }
            $params=[$key,$name,$input['description'] ?? '',$ranges,$price,$currency,$enabled];
            if($id){Db::exec('UPDATE static_ip_pools SET pool_key=?,name=?,description=?,ip_ranges=?,price=?,currency=?,enabled=?,updated_at=NOW() WHERE id=?',array_merge($params,[$id]));}
            else{Db::exec('INSERT INTO static_ip_pools (pool_key,name,description,ip_ranges,price,currency,enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',$params);$id=(int)$pdo->lastInsertId();}
            $pdo->commit();return $id;
        }catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }
    public static function delete(int $id): void
    {
        $pdo=Db::pdo();$pdo->beginTransaction();
        try{
            $pools=Db::select('SELECT * FROM static_ip_pools ORDER BY id FOR UPDATE');
            foreach($pools as $pool){if((int)$pool['id']===$id){self::assertUnassigned(self::parse($pool['ip_ranges']));Db::exec('DELETE FROM static_ip_pools WHERE id=?',[$id]);}}
            $pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }
    private static function assertUnassigned(array $ips): void
    {
        foreach(array_chunk($ips,256) as $chunk){
            if(Db::value('SELECT COUNT(*) FROM customer_profiles WHERE framed_ip_address IN ('.implode(',',array_fill(0,count($chunk),'?')).')',$chunk)){throw new RuntimeException('Assigned addresses, including archived reservations, must remain in their pool.');}
        }
    }
}
