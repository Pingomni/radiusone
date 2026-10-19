<?php
declare(strict_types=1);
namespace App\Support;
final class NotificationQueue
{
    public static function due(string $expiry, int $days, string $clock): string
    {
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$clock)){throw new \RuntimeException('Invalid reminder time.');}
        return (new \DateTimeImmutable($expiry.' '.$clock))->modify('-'.max(0,min(365,$days)).' days')->format('Y-m-d H:i:s');
    }
    public static function enqueue(array $settings): int
    {
        $today=Entitlement::today();$target=(new \DateTimeImmutable($today))->modify('+'.max(0,min(365,(int)$settings['days_before'])).' days')->format('Y-m-d');
        $count=0;
        foreach(Db::select('SELECT id,username,expiry_date FROM customer_profiles WHERE enabled=1 AND account_status<>"archived" AND expiry_date BETWEEN ? AND ?',[$today,$target]) as $client){
            foreach(['sms','email'] as $channel){
                if($settings[$channel.'_enabled']!=='1'){continue;}
                // Preserve legacy sent evidence; never infer permission to resend it.
                $sent=Db::value('SELECT COUNT(*) FROM notification_logs WHERE username=? AND channel=? AND event_type="expiry_reminder" AND expiry_date=? AND status="sent"',[$client['username'],$channel,$client['expiry_date']]);
                $due=self::due($client['expiry_date'],(int)$settings['days_before'],$settings['send_time']);
                $count+=Db::exec('INSERT INTO notification_jobs(customer_id,channel,expiry_date,due_at,next_attempt_at,state) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$client['id'],$channel,$client['expiry_date'],$due,$due,$sent?'sent':'pending']);
            }
        }
        return $count;
    }
    public static function claim(): ?array
    {
        // A crashed/timed-out sender may have delivered. Do not automatically reclaim it.
        Db::exec('UPDATE notification_jobs SET state="uncertain",error_class="InterruptedSender" WHERE state="sending" AND claimed_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)');
        $token=bin2hex(random_bytes(24));
        Db::exec('UPDATE notification_jobs SET state="sending",claim_token=?,claimed_at=NOW(),attempts=attempts+1 WHERE state="pending" AND attempts<3 AND next_attempt_at<=NOW() ORDER BY due_at,id LIMIT 1',[$token]);
        return Db::row('SELECT * FROM notification_jobs WHERE claim_token=? AND state="sending"',[$token]);
    }
    public static function process(array $settings, ?\Closure $deliver=null, int $limit=100): array
    {
        $stats=['checked'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'message'=>'ok'];
        if($settings['enabled']!=='1'){$stats['message']='disabled';return $stats;}
        self::enqueue($settings);
        $templates=NotificationService::templates();
        $deliver ??= static function($job,$client)use($templates){
            if($job['channel']==='sms'){
                $phone=trim((string)(($client['cell_phone'] ?? '') ?: ($client['phone'] ?? '')));
                if($phone===''){throw new \RuntimeException('Missing phone.');}
                NotificationService::sendSms($client,$phone,$templates['sms_expiry_body']);
            }else{NotificationService::sendEmail($client,$templates['email_expiry_subject'],$templates['email_expiry_body']);}
        };
        for($i=0;$i<max(1,min(100,$limit));$i++){
            $job=self::claim();if(!$job){break;}$stats['checked']++;
            $client=Db::row('SELECT c.*,s.name AS service_name FROM customer_profiles c LEFT JOIN service_metadata s ON s.group_name=c.service_group WHERE c.id=?',[$job['customer_id']]);
            $state='sent';$error=null;
            if(!$client || !$client['enabled'] || $client['account_status']==='archived' || $client['expiry_date']!==$job['expiry_date'] || $client['expiry_date']<Entitlement::today() || $settings[$job['channel'].'_enabled']!=='1' || ($settings['respect_client_flags']==='1' && !$client[$job['channel'].'_alert'])){
                $state='cancelled';$stats['skipped']++;
            }else{
                $client['notification_id']='reminder-'.$job['id'];
                try{$deliver($job,$client);$stats['sent']++;}
                catch(NotificationRetry $e){$state=(int)$job['attempts']<3?'pending':'failed';$error=get_class($e);$stats['failed']++;}
                catch(\Throwable $e){$state='uncertain';$error=get_class($e);$stats['failed']++;}
            }
            // Keep the claim until outcome + historical log commit together.
            $pdo=Db::pdo();$pdo->beginTransaction();
            try{
                $changed=Db::exec('UPDATE notification_jobs SET state=?,error_class=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),completed_at=IF(?="pending",NULL,NOW()) WHERE id=? AND state="sending" AND claim_token=?',[$state,$error,$state,$job['id'],$job['claim_token']]);
                if($changed!==1){throw new \RuntimeException('Notification claim changed; delivery requires review.');}
                if($client){Db::exec('INSERT INTO notification_logs(username,channel,event_type,expiry_date,status,message,error,sent_at) VALUES(?,?,"expiry_reminder",?,?,"",?,NOW())',[$client['username'],$job['channel'],$job['expiry_date'],$state==='sent'?'sent':($state==='cancelled'?'skipped':'failed'),$error]);}
                $pdo->commit();
            }catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
        }
        return $stats;
    }
}
