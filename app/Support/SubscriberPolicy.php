<?php
declare(strict_types=1);
namespace App\Support;
use RuntimeException;
final class SubscriberPolicy
{
    private static array $locks=[];
    public static function releaseLocks(): void
    {
        if(Db::pdo()->inTransaction()){return;}
        foreach(self::$locks as $lock){Db::value('SELECT RELEASE_LOCK(?)',[$lock]);}
        self::$locks=[];
    }
    public static function ensure(int $customerId): array
    {
        if(!Db::pdo()->inTransaction()){throw new RuntimeException('Policy publication requires a transaction.');}
        Db::exec('INSERT IGNORE INTO billing_accounts (legacy_customer_id,created_at) VALUES (?,NOW())',[$customerId]);
        Db::exec('INSERT IGNORE INTO subscriptions (customer_id,billing_account_id,created_at,updated_at) SELECT ?,id,NOW(),NOW() FROM billing_accounts WHERE legacy_customer_id=?',[$customerId,$customerId]);
        $sub=Db::row('SELECT * FROM subscriptions WHERE customer_id=? FOR UPDATE',[$customerId]);
        $lock='frweb:subscription:'.substr(hash('sha256',(string)Db::value('SELECT DATABASE()')),0,20).':'.$sub['id'];
        if(!isset(self::$locks[$lock])){
            if((int)Db::value('SELECT GET_LOCK(?,0)',[$lock])!==1){throw new RuntimeException('A network operation is in progress for this subscription. Retry the same change.');}
            self::$locks[$lock]=$lock;
        }
        return $sub;
    }
    public static function bind(array $client,array $input): void
    {
        $sub=self::ensure((int)$client['id']);
        if(!array_key_exists('access_profile_id',$input)){return;}
        $id=empty($input['access_profile_id'])?null:(int)$input['access_profile_id'];
        if($id!==null){
            $p=Db::row('SELECT * FROM nas_access_profiles WHERE id=?',[$id]);
            if(!$p || AccessPolicy::adapter($p['adapter'])->isPpp()!==($client['auth_method']==='pppoe')){throw new RuntimeException('Access profile does not match the subscription access method.');}
        }
        $circuit=trim((string)($input['circuit_id']??''));
        if(strlen($circuit)>190 || preg_match('/[\x00-\x1f\x7f]/',$circuit)){throw new RuntimeException('Invalid access-circuit identity.');}
        Db::exec('UPDATE subscriptions SET access_profile_id=?,dhcp_client_id=?,circuit_id=?,dirty=1 WHERE id=?',[$id,AccessPolicy::clientId($input['dhcp_client_id']??null),$circuit?:null,$sub['id']]);
    }
    /** Publish once per changed effective policy, atomically with the originating business transaction. */
    public static function publish(array $client,string $effectiveGroup): void
    {
        SchemaCompatibility::require('policy');
        $sub=self::ensure((int)$client['id']);
        $profile=$sub['access_profile_id']?Db::row('SELECT * FROM nas_access_profiles WHERE id=?',[$sub['access_profile_id']]):null;
        $plan=Db::row('SELECT * FROM service_metadata WHERE group_name=?',[$effectiveGroup])??[];
        $selected=Db::row('SELECT policy_revision FROM service_metadata WHERE group_name=?',[$client['service_group']]);
        $key=$profile['adapter']??($client['auth_method']==='dhcp'?'routeros_dhcp':'routeros_pppoe');
        $adapter=AccessPolicy::adapter($key);
        if($adapter->isPpp()!==($client['auth_method']==='pppoe')){throw new RuntimeException('Access profile and protocol mismatch.');}
        $blocked=empty($client['enabled']) || in_array($client['account_status'],['disabled','suspended','archived'],true);
        $expired=Entitlement::isExpired($client);
        // Restricted service is not a forwarding deny. Keep that distinction in policy/diagnostics.
        $entitled=!$blocked && (!$expired || $effectiveGroup!==$client['service_group']);
        $gate=AccessPolicy::capability($profile);
        $reason=$blocked?'administrative_suspension':($expired && !$entitled?'commercial_expiry':($gate?'access_profile_unverified':($expired?'restricted_service':'entitled')));
        $until=!$expired && !empty($client['expiry_date'])?Entitlement::expiryInstant($client['expiry_date']):null;
        $replies=$adapter->replies($plan,$client);
        $policy=['adapter'=>$key,'username'=>$client['username'],'source_ip'=>$profile['source_ip']??'', 'listener_key'=>$profile['listener_key']??'',
            'profile_id'=>$profile['id']??null,'profile_revision'=>$profile['revision']??null,
            'circuit_id'=>$sub['circuit_id']??'', 'dhcp_server'=>$profile['dhcp_server']??'', 'dhcp_client_id'=>$sub['dhcp_client_id'],
            'mac_address'=>($client['auth_method']==='dhcp' || !empty($client['mac_lock']))?($client['mac_address']??''):'',
            'allowed'=>$entitled && $gate===null,'valid_until'=>$until,'reason'=>$reason,'entitlement'=>$blocked?'suspended':($expired?($entitled?'restricted':'expired'):'active'),
            'effective_group'=>$effectiveGroup,'replies'=>$replies];
        if($policy['mac_address']!==''){$policy['mac_address']=AccessPolicy::mac($policy['mac_address']);}
        $json=json_encode($policy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$hash=hash('sha256',$json);
        $revision=(int)$sub['policy_revision'];
        $changed=$hash!==$sub['policy_hash'];
        if($changed) {
            $revision++;
            Db::exec('INSERT INTO policy_revisions (subscription_id,revision,policy_json,created_at) VALUES (?,?,?,NOW())',[$sub['id'],$revision,$json]);
            $previous=$sub['policy_revision']?Db::value('SELECT policy_json FROM policy_revisions WHERE subscription_id=? AND revision=?',[$sub['id'],$sub['policy_revision']]):null;
            $previous=$previous?json_decode($previous,true,512,JSON_THROW_ON_ERROR):[];
            $action=(!$entitled)?'suspend':(($previous['entitlement']??'')==='suspended' || ($previous['entitlement']??'')==='expired'?'restore':'apply');
            Db::exec('INSERT INTO network_operations (subscription_id,policy_revision,action,dedupe_key,available_at,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW(),NOW())',[$sub['id'],$revision,$action,$sub['id'].':'.$revision.':'.$action]);
        }
        $source=$blocked?($sub['suspension_source']?:'manual'):null;
        Db::exec('UPDATE subscriptions SET policy_hash=?,policy_revision=?,service_revision=?,dirty=0,next_transition_at=?,suspension_source=?,provisioning_state=IF(?,"pending",provisioning_state),last_error=IF(?, ?,last_error),updated_at=NOW() WHERE id=?',
            [$hash,$revision,(int)($selected['policy_revision']??0),$until?date('Y-m-d H:i:s',$until):null,$source,(int)$changed,(int)$changed,$gate,$sub['id']]);
        // Renames remove only authorization publication; raw events/identity history are untouched.
        Db::exec('DELETE FROM access_policies WHERE subscription_id=? AND username<>?',[$sub['id'],$client['username']]);
        Db::exec('INSERT INTO access_policies (username,subscription_id,revision,adapter,source_ip,listener_key,circuit_id,dhcp_server,dhcp_client_id,mac_address,allowed,valid_until,reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE subscription_id=VALUES(subscription_id),revision=VALUES(revision),adapter=VALUES(adapter),source_ip=VALUES(source_ip),listener_key=VALUES(listener_key),circuit_id=VALUES(circuit_id),dhcp_server=VALUES(dhcp_server),dhcp_client_id=VALUES(dhcp_client_id),mac_address=VALUES(mac_address),allowed=VALUES(allowed),valid_until=VALUES(valid_until),reason=VALUES(reason)',
            [$client['username'],$sub['id'],$revision,$key,$policy['source_ip'],$policy['listener_key'],$policy['circuit_id'],$policy['dhcp_server'],$policy['dhcp_client_id'],$policy['mac_address'],(int)$policy['allowed'],$until,$reason]);
        if($profile || $client['auth_method']==='dhcp'){
            // A private membership prevents vendor/PPP leakage from a shared legacy plan group.
            $group='access_'.$sub['id'];
            Db::exec('DELETE FROM radgroupreply WHERE groupname=?',[$group]);
            Db::exec('DELETE FROM radgroupcheck WHERE groupname=?',[$group]);
            Db::exec('DELETE FROM radreply WHERE username=?',[$client['username']]);
            foreach($replies as [$attribute,$op,$value]){Db::exec('INSERT INTO radreply (username,attribute,op,value) VALUES (?,?,?,?)',[$client['username'],$attribute,$op,$value]);}
            if($adapter->isPpp() && !empty($plan['simultaneous_use'])){Db::exec('INSERT INTO radgroupcheck (groupname,attribute,op,value) VALUES (?,"Simultaneous-Use",":=",?)',[$group,$plan['simultaneous_use']]);}
            Db::exec('UPDATE radusergroup SET groupname=? WHERE username=?',[$group,$client['username']]);
            if(!$policy['allowed']){
                Db::exec('DELETE FROM radcheck WHERE username=? AND attribute="Auth-Type"',[$client['username']]);
                Db::exec('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,"Auth-Type",":=","Reject")',[$client['username']]);
            }
        }
    }
    public static function due(int $limit=100): int
    {
        SchemaCompatibility::require('policy');$limit=max(1,min(500,$limit));$processed=0;
        // Bounded row claims; SKIP LOCKED permits independent workers without duplicate publication.
        while($processed<$limit){
            $pdo=Db::pdo();$pdo->beginTransaction();
            try{
                $row=Db::row('SELECT c.username FROM subscriptions u JOIN customer_profiles c ON c.id=u.customer_id JOIN service_metadata s ON s.group_name=c.service_group WHERE c.account_status<>"archived" AND (u.dirty=1 OR u.next_transition_at<=NOW() OR u.service_revision<>s.policy_revision) ORDER BY u.dirty DESC,u.next_transition_at,u.id LIMIT 1 FOR UPDATE SKIP LOCKED');
                if(!$row){$pdo->commit();self::releaseLocks();break;}
                RadiusRepository::provisionClient($row['username']);$pdo->commit();self::releaseLocks();$processed++;
            }catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();self::releaseLocks();}throw $e;}
        }
        return $processed;
    }
}
