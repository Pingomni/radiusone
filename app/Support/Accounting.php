<?php
declare(strict_types=1);
namespace App\Support;
/** Read-only interpretation of cumulative radacct snapshots; never a period-usage ledger. */
final class Accounting
{
    public static function state(array $row, ?int $now=null): string
    {
        if(!empty($row['acctstoptime'])){return 'closed';}
        $stamp=$row['acctupdatetime'] ?? $row['acctstarttime'] ?? null;
        $time=$stamp ? strtotime($stamp) : false;$now ??= time();
        if($time===false || $time<strtotime('2000-01-01') || $time>$now){return 'unknown';}
        return $time >= $now-self::window($row) ? 'fresh accounting' : 'stale accounting';
    }
    public static function anomalies(array $r, ?int $now=null): array
    {
        $now ??= time();$issues=[];$start=null;
        foreach(['acctstarttime','acctupdatetime','acctstoptime'] as $key){
            if(empty($r[$key])){continue;}$t=strtotime($r[$key]);
            if($t===false || $t<strtotime('2000-01-01')){$issues[]=$key.': invalid/pre-2000';}
            elseif($t>$now){$issues[]=$key.': future';}
            if($key==='acctstarttime'){$start=$t;}
            elseif($t!==false && $start!==null && $start!==false && $t<$start){$issues[]=$key.': before start';}
        }
        foreach(['acctinputoctets','acctoutputoctets','acctsessiontime'] as $key){if(isset($r[$key]) && (int)$r[$key]<0){$issues[]=$key.': negative';}}
        return $issues;
    }
    public static function window(array $row): int
    {
        if(isset($row['expected_interval'])){return max(30,(int)$row['expected_interval'])+max(30,(int)($row['grace_seconds']??120));}
        if(!empty($row['username']) && !empty($row['nasipaddress']) && !SchemaCompatibility::problems('policy')){
            $p=Db::row('SELECT p.expected_interval,p.grace_seconds FROM subscriptions s JOIN nas_access_profiles p ON p.id=s.access_profile_id JOIN customer_profiles c ON c.id=s.customer_id WHERE p.source_ip=? AND (c.username=? OR c.id IN (SELECT customer_id FROM customer_identity_history WHERE username=?)) LIMIT 1',[$row['nasipaddress'],$row['username'],$row['username']]);
            if($p){return (int)$p['expected_interval']+(int)$p['grace_seconds'];}
        }
        return 300; // conservative legacy fallback; no interval was recorded
    }
    public static function freshSql(string $alias): string
    {
        if(!preg_match('/^[a-z][a-z0-9_]*$/D',$alias)){throw new \RuntimeException('Invalid accounting alias.');}
        $window='300';
        if(!SchemaCompatibility::problems('policy')){
            $window="COALESCE((SELECT ap.expected_interval+ap.grace_seconds FROM subscriptions su JOIN nas_access_profiles ap ON ap.id=su.access_profile_id JOIN customer_profiles cp ON cp.id=su.customer_id WHERE ap.source_ip=$alias.nasipaddress AND (cp.username=$alias.username OR cp.id IN (SELECT ih.customer_id FROM customer_identity_history ih WHERE ih.username=$alias.username)) LIMIT 1),300)";
        }
        return "$alias.acctstoptime IS NULL AND COALESCE($alias.acctupdatetime,$alias.acctstarttime) BETWEEN DATE_SUB(NOW(), INTERVAL ($window) SECOND) AND NOW()";
    }
    public static function quality(): array
    {
        $fresh=self::freshSql('r');
        return Db::row('SELECT COUNT(*) AS rows_total,
            SUM(acctstarttime IS NULL OR acctstarttime<"2000-01-01" OR acctstarttime>NOW() OR acctupdatetime<acctstarttime OR acctstoptime<acctstarttime OR acctupdatetime>NOW() OR acctstoptime>NOW() OR acctinputoctets<0 OR acctoutputoctets<0) AS anomalous,
            SUM(acctstoptime IS NULL AND NOT COALESCE(('.$fresh.'),0)) AS stale_or_unknown
            FROM radacct r') ?? [];
    }
}
