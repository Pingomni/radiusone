<?php
declare(strict_types=1);
namespace App\Support;
final class AccessProfiles
{
    public static function save(array $input): int
    {
        SchemaCompatibility::require('policy');
        $lock='frweb:network:'.substr(hash('sha256',(string)Db::value('SELECT DATABASE()')),0,24).':'.(int)($input['nas_id']??0);
        if((int)Db::value('SELECT GET_LOCK(?,0)',[$lock])!==1){throw new \RuntimeException('A network operation is active on this NAS; retry the profile change shortly.');}
        try{return self::saveLocked($input);}finally{Db::value('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private static function saveLocked(array $input): int
    {
        $adapter=AccessPolicy::adapter((string)($input['adapter']??''));
        $listener=(string)($input['listener_key']??'');
        if(!preg_match('/^[a-z0-9_]{1,80}$/D',$listener)){throw new \RuntimeException('A trusted listener key is required.');}
        $nas=Db::row('SELECT n.id,COALESCE(m.vendor,n.type) AS vendor,n.nasname FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id WHERE n.id=?',[(int)($input['nas_id']??0)]);
        if(!$nas){throw new \RuntimeException('NAS not found.');}
        // The trusted source is always the registered NAS IP; it is not a separate input.
        $source=(string)$nas['nasname'];
        if(!filter_var($source,FILTER_VALIDATE_IP)){throw new \RuntimeException('Set the NAS IP address to a fixed IP before creating access profiles.');}
        if((str_starts_with($adapter->key(),'routeros') && $nas['vendor']!=='mikrotik') || (str_starts_with($adapter->key(),'iosxe') && $nas['vendor']!=='cisco')){throw new \RuntimeException('Profile vendor differs from the registered NAS.');}
        $data=[];
        foreach(['model','software_version','dhcp_server','evidence'] as $key){$data[$key]=trim((string)($input[$key]??''));}
        if(!$adapter->isPpp() && $data['dhcp_server']===''){throw new \RuntimeException('Local DHCP server identity is required.');}
        $interval=(int)($input['expected_interval']??300);$grace=(int)($input['grace_seconds']??120);
        if($interval<30 || $interval>86400 || $grace<30 || $grace>3600){throw new \RuntimeException('Accounting interval must be 30–86400 seconds; grace 30–3600 seconds.');}
        foreach(['enabled','authorization_verified','actions_verified','forwarding_verified'] as $key){$data[$key]=empty($input[$key])?0:1;}
        if(($data['authorization_verified'] || $data['actions_verified'] || $data['forwarding_verified']) && ($data['model']==='' || $data['software_version']==='' || $data['evidence']==='')){throw new \RuntimeException('Record an exact model/release and acceptance evidence before enabling capabilities.');}
        $pdo=Db::pdo();$pdo->beginTransaction();
        try {
            $id=(int)($input['id']??0);
            if($id){
                $old=Db::row('SELECT * FROM nas_access_profiles WHERE id=? FOR UPDATE',[$id]);
                if(!$old || (int)$old['nas_id']!==(int)$nas['id']){throw new \RuntimeException('Access profile not found on this NAS.');}
                if($old['source_ip']!==$source){throw new \RuntimeException('This profile is bound to the previous NAS IP '.$old['source_ip'].'. Create a new profile for the current NAS IP '.$source.'.');}
                if($old['adapter']!==$adapter->key() || $old['listener_key']!==$listener){throw new \RuntimeException('Create a new profile to change its protocol or trusted context.');}
                Db::exec('UPDATE nas_access_profiles SET model=?,software_version=?,dhcp_server=?,evidence=?,enabled=?,authorization_verified=?,actions_verified=?,forwarding_verified=?,expected_interval=?,grace_seconds=?,revision=revision+1 WHERE id=?',[...array_values($data),$interval,$grace,$id]);
                Db::exec('UPDATE access_policies p JOIN subscriptions s ON s.id=p.subscription_id SET p.allowed=0,p.reason="profile_changed" WHERE s.access_profile_id=?',[$id]);
                Db::exec('UPDATE subscriptions SET dirty=1 WHERE access_profile_id=?',[$id]);
            }else{
                Db::exec('INSERT INTO nas_access_profiles (nas_id,adapter,source_ip,listener_key,model,software_version,dhcp_server,evidence,enabled,authorization_verified,actions_verified,forwarding_verified,expected_interval,grace_seconds) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$nas['id'],$adapter->key(),$source,$listener,...array_values($data),$interval,$grace]);$id=(int)$pdo->lastInsertId();
            }
            Audit::log('save_access_profile','nas_access_profile',(string)$id,null,['adapter'=>$adapter->key(),'nas_id'=>$nas['id'],'release'=>$data['software_version'],'evidence'=>$data['evidence']]);
            $pdo->commit();return $id;
        }catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }
}
