<?php
declare(strict_types=1);
namespace App\Support;
use InvalidArgumentException;
use RuntimeException;

/** Business events and reviewed bulk messages share one durable, immutable outbox. */
final class CustomerNotifications
{
    public const LABELS = ['welcome'=>'Welcome', 'extended'=>'Service extended', 'custom'=>'Custom'];
    public const TOKENS = ['name','username','service','expiry','app_name','phone','email'];

    public static function templates(): array
    {
        $saved = [];
        foreach (Db::select('SELECT setting_key,setting_value FROM settings WHERE setting_key LIKE "notification_template_%"') as $row) $saved[$row['setting_key']] = $row['setting_value'];
        $defaults = [
            'welcome'=>['Welcome to {app_name}', "Hello {name},\n\nWelcome! Your {service} internet service is now active.\nUsername: {username}\nService valid until: {expiry}\n\nThank you for choosing {app_name}."],
            'extended'=>['Your service has been extended', "Hello {name},\n\nYour {service} internet service has been extended until {expiry}.\n\nThank you,\n{app_name}"],
            'custom'=>['An update from {app_name}', 'Hello {name}, '],
        ];
        $result = [];
        foreach ($defaults as $key=>[$subject,$body]) {
            foreach (['email_enabled'=>'0','sms_enabled'=>'0','subject'=>$subject,'email_body'=>$body,'sms_body'=>str_replace("\n\n", ' ', str_replace("\n", ' ', $body))] as $field=>$default) {
                $result[$key][$field] = (string)($saved['notification_template_'.$key.'_'.$field] ?? $default);
            }
        }
        return $result;
    }

    public static function ready(string $channel): bool
    {
        if ($channel === 'sms') {
            $s = NotificationService::smsGatewaySettings();
            $payload=$s['url'].($s['method']==='POST'?$s['body']:'');
            $host=strtolower((string)parse_url($s['url'],PHP_URL_HOST));
            $allowed=array_map('trim',explode(',',strtolower((string)App::env('NOTIFICATION_HTTP_ALLOWED_HOSTS',''))));
            return $s['enabled'] === '1' && in_array($host,$allowed,true) && (bool)preg_match('#^https?://#i', $s['url']) && str_contains($payload, '{phone}') && str_contains($payload, '{message}');
        }
        if ($channel !== 'email') return false;
        $s = NotificationService::emailGatewaySettings();
        return $s['enabled'] === '1' && (bool)filter_var($s['from_email'], FILTER_VALIDATE_EMAIL)
            && ($s['transport'] === 'mail' || trim($s['host']) !== '');
    }

    public static function validateText(string $subject, string $body, string $channel): void
    {
        if (!in_array($channel, ['email','sms'], true)) throw new InvalidArgumentException('Choose Email or SMS.');
        if (trim($body) === '' || strlen($body) > ($channel === 'sms' ? 1600 : 16000)) throw new InvalidArgumentException($channel === 'sms' ? 'Enter an SMS message of up to 1,600 bytes.' : 'Enter an email message of up to 16,000 bytes.');
        if ($channel === 'email' && (trim($subject) === '' || strlen($subject) > 200 || preg_match('/[\r\n]/', $subject))) throw new InvalidArgumentException('Enter an email subject of up to 200 bytes on one line.');
        preg_match_all('/\{([^{}]+)\}/', $subject.' '.$body, $matches);
        foreach ($matches[1] as $token) if (!in_array($token, self::TOKENS, true)) throw new InvalidArgumentException('Unknown placeholder: {'.$token.'}.');
    }

    public static function saveTemplate(string $key, array $input): void
    {
        if (!isset(self::LABELS[$key])) throw new InvalidArgumentException('Unknown template.');
        $values = ['subject'=>trim((string)($input['subject']??'')), 'email_body'=>trim((string)($input['email_body']??'')), 'sms_body'=>trim((string)($input['sms_body']??''))];
        foreach (['email','sms'] as $channel) {
            $values[$channel.'_enabled'] = isset($input[$channel.'_enabled']) ? '1' : '0';
            self::validateText($values['subject'], $values[$channel.'_body'], $channel);
            if ($values[$channel.'_enabled'] === '1' && !self::ready($channel)) throw new InvalidArgumentException('Enable and configure the '.$channel.' gateway first.');
        }
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            foreach ($values as $field=>$value) Db::exec('INSERT INTO settings(setting_key,setting_value,is_secret,updated_by,updated_at) VALUES (?,?,0,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()', ['notification_template_'.$key.'_'.$field,$value,Auth::user()['id']??null]);
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public static function customer(int $id): ?array
    {
        return Db::row('SELECT c.*,s.name AS service_name FROM customer_profiles c LEFT JOIN service_metadata s ON s.group_name=c.service_group WHERE c.id=?', [$id]) ?: null;
    }

    public static function eligibility(array $c, string $channel): array
    {
        $recipient = $channel === 'email' ? trim((string)($c['email']??'')) : trim((string)(($c['cell_phone']??'') ?: ($c['phone']??'')));
        if (($c['account_status']??'') === 'archived') return [$recipient,'Customer archived'];
        if (empty($c[$channel.'_alert'])) return [$recipient,'Customer opted out'];
        if ($channel === 'email' ? !filter_var($recipient,FILTER_VALIDATE_EMAIL) : !preg_match('/^\+?[0-9][0-9 ()-]{5,24}$/D',$recipient)) return [$recipient,'Missing or invalid contact'];
        return [$recipient,null];
    }

    /** Must participate in the customer/business transaction. */
    public static function enrolled(int $id): void
    {
        self::requireTransaction();
        Db::exec('INSERT INTO notification_onboarding(customer_id) VALUES (?)',[$id]);
    }

    public static function extended(int $id, ?string $before, ?string $after): void
    {
        self::requireTransaction();
        if (!$before || !$after || $after <= $before) return;
        self::automatic('extended', $id, 'extended:'.$id.':'.$after);
    }

    /** Called only inside the transaction that records verified activation. */
    public static function activated(int $subscriptionId): void
    {
        self::requireTransaction();
        $s = Db::row('SELECT s.customer_id FROM subscriptions s JOIN access_policies p ON p.subscription_id=s.id AND p.revision=s.policy_revision WHERE s.id=? AND s.provisioning_state="verified" AND s.applied_revision=s.policy_revision AND p.allowed=1 AND (p.valid_until IS NULL OR p.valid_until>UNIX_TIMESTAMP())',[$subscriptionId]);
        if (!$s) return;
        $changed = Db::exec('UPDATE notification_onboarding SET activated_at=UTC_TIMESTAMP() WHERE customer_id=? AND activated_at IS NULL',[$s['customer_id']]);
        if ($changed) self::automatic('welcome',(int)$s['customer_id'],'welcome:'.$s['customer_id']);
    }

    private static function requireTransaction(): void
    {
        if (!Db::pdo()->inTransaction()) throw new RuntimeException('Notification events require a business transaction.');
    }

    private static function automatic(string $key, int $id, string $eventKey): void
    {
        $event = self::event($key,$eventKey,null);
        if ($event === null) return;
        $c = self::customer($id);
        if (!$c) return;
        $template = self::templates()[$key];
        NotificationService::withSettings(function () use ($event,$key,$c,$template): void {
            foreach (['email','sms'] as $channel) {
                if ($template[$channel.'_enabled'] !== '1') continue;
                [$recipient,$reason] = self::eligibility($c,$channel);
                if (!self::ready($channel)) $reason = 'Gateway disabled or incomplete';
                $item = self::message($c,$channel,$template['subject'],$template[$channel.'_body']);
                self::insert($event,$item,$reason);
            }
        });
    }

    private static function event(string $key,string $eventKey,?int $actor): ?int
    {
        // The business transaction locks its customer; the unique key also protects concurrent replays.
        $changed = Db::exec('INSERT IGNORE INTO notification_events(event_key,template_key,actor_id) VALUES (?,?,?)',[$eventKey,$key,$actor]);
        return $changed ? (int)Db::pdo()->lastInsertId() : null;
    }

    private static function message(array $c,string $channel,string $subject,string $body): array
    {
        [$recipient,$reason] = self::eligibility($c,$channel);
        $renderedSubject = $channel === 'email' ? NotificationService::applyTemplate($subject,$c,[],false) : '';
        $renderedBody = NotificationService::applyTemplate($body,$c,[],false);
        if (strlen($renderedSubject)>998 || strlen($renderedBody)>60000) throw new InvalidArgumentException('Personalized message is too long.');
        return ['customer_id'=>(int)$c['id'],'username'=>$c['username'],'channel'=>$channel,'recipient'=>$recipient,'subject'=>$renderedSubject,'body'=>$renderedBody,'expiry_date'=>$c['expiry_date']??null,'reason'=>$reason];
    }

    private static function insert(int $event,array $item,?string $reason): void
    {
        Db::exec('INSERT INTO notification_deliveries(event_id,customer_id,channel,recipient,subject,body,expiry_date,state,reason,completed_at) VALUES (?,?,?,?,?,?,?,?,?,IF(? IS NULL,NULL,UTC_TIMESTAMP()))',[$event,$item['customer_id'],$item['channel'],$item['recipient'],$item['subject'],$item['body'],$item['expiry_date'],$reason?'skipped':'pending',$reason,$reason]);
    }

    /** Server-owned preview; confirmation never accepts a message or recipient list from the browser. */
    public static function preview(array $input,int $actor): array
    {
        $channel = (string)($input['channel']??'email');
        $subject = trim((string)($input['subject']??'')); $body = trim((string)($input['body']??''));
        self::validateText($subject,$body,$channel);
        if (!self::ready($channel)) throw new InvalidArgumentException('Configure and enable this gateway before sending.');
        $audience = (string)($input['audience']??'selected');
        $params = [];
        if ($audience === 'active') {
            $where = 'c.enabled=1 AND c.account_status="active" AND (c.expiry_date IS NULL OR c.expiry_date>=CURDATE())';
        } elseif ($audience === 'selected') {
            $ids = array_values(array_unique(array_filter(array_map('intval',(array)($input['recipients']??[])),static fn($v)=>$v>0)));
            if (!$ids || count($ids)>1000) throw new InvalidArgumentException('Select between 1 and 1,000 customers.');
            $where = 'c.id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; $params=$ids;
        } else throw new InvalidArgumentException('Choose a recipient group.');
        $clients = Db::select('SELECT c.*,s.name AS service_name FROM customer_profiles c LEFT JOIN service_metadata s ON s.group_name=c.service_group WHERE '.$where.' ORDER BY c.username LIMIT 1001',$params);
        if (count($clients)>1000) throw new InvalidArgumentException('This group has more than 1,000 customers. Select a smaller group.');
        if (!$clients) throw new InvalidArgumentException('No customers match this group.');
        $items = []; $eligible=0;
        NotificationService::withSettings(function () use ($clients,$channel,$subject,$body,&$items,&$eligible): void {
            foreach ($clients as $c) { $item=self::message($c,$channel,$subject,$body); $items[]=$item; if (!$item['reason']) $eligible++; }
        });
        return ['token'=>bin2hex(random_bytes(24)),'actor'=>$actor,'expires'=>time()+1800,'channel'=>$channel,'items'=>$items,'eligible'=>$eligible,'source'=>['subject'=>$subject,'body'=>$body,'audience'=>$audience,'recipients'=>$ids??[]]];
    }

    public static function confirm(array $draft,int $actor): int
    {
        if (($draft['actor']??null)!==$actor || ($draft['expires']??0)<time()) throw new InvalidArgumentException('This preview expired. Review your message again.');
        if (empty($draft['eligible'])) throw new InvalidArgumentException('No eligible recipients.');
        if (!self::ready($draft['channel'])) throw new InvalidArgumentException('This gateway is disabled. Enable it and review your message again.');
        $pdo=Db::pdo();$pdo->beginTransaction();
        try {
            $event=self::event('custom','bulk:'.$draft['token'],$actor);
            if ($event!==null) foreach ($draft['items'] as $item) self::insert($event,$item,$item['reason']);
            $event ??= (int)Db::value('SELECT id FROM notification_events WHERE event_key=?',['bulk:'.$draft['token']]);
            $pdo->commit(); return $event;
        } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    /** A send with an unknown outcome is never automatically retried. */
    public static function process(?\Closure $transport=null,int $limit=25): array
    {
        SchemaCompatibility::require('notifications');
        $counts=['sent'=>0,'skipped'=>0,'uncertain'=>0]; $deadline=microtime(true)+40;
        $counts['uncertain'] += Db::exec('UPDATE notification_deliveries SET state="uncertain",reason="Worker interrupted; check provider",completed_at=UTC_TIMESTAMP() WHERE state="sending" AND claimed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)');
        for ($i=0;$i<min(100,max(0,$limit)) && microtime(true)<$deadline;$i++) {
            $token=bin2hex(random_bytes(16));
            $changed=Db::exec('UPDATE notification_deliveries SET state="sending",claim_token=?,claimed_at=UTC_TIMESTAMP() WHERE state="pending" ORDER BY id LIMIT 1',[$token]);
            if (!$changed) break;
            $job=Db::row('SELECT d.*,e.template_key FROM notification_deliveries d JOIN notification_events e ON e.id=d.event_id WHERE d.claim_token=?',[$token]);
            $state='skipped'; $reason=null;
            try {
                $c=self::customer((int)$job['customer_id']);
                if (!$c) $reason='Customer no longer exists';
                else {
                    [$recipient,$reason]=self::eligibility($c,$job['channel']);
                    if ($recipient!==$job['recipient']) $reason='Contact changed since preview';
                    if (!self::ready($job['channel'])) $reason='Gateway disabled or incomplete';
                    if ($job['template_key']!=='custom' && $job['expiry_date'] && $c['expiry_date'] && $c['expiry_date']<$job['expiry_date']) $reason='Service end date changed';
                    if ($job['template_key']==='welcome' && (empty($c['enabled']) || $c['account_status']!=='active')) $reason='Customer no longer active';
                    if ($job['template_key']!=='custom' && self::templates()[$job['template_key']][$job['channel'].'_enabled']!=='1') $reason='Automatic template disabled';
                }
                if ($reason===null) {
                    // Freeze content: the transport must not expand placeholders a second time.
                    if ($transport) $transport($job);
                    elseif ($job['channel']==='email') NotificationService::sendEmail(['email'=>$job['recipient']],$job['subject'],$job['body'],true);
                    else NotificationService::sendSms(['notification_id'=>'event-'.$job['id']],$job['recipient'],$job['body'],true);
                    $state='sent';
                }
            } catch (\Throwable $e) { $state='uncertain';$reason='Provider outcome unknown; check provider'; }
            Db::exec('UPDATE notification_deliveries SET state=?,reason=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND state="sending" AND claim_token=?',[$state,$reason,$job['id'],$token]);
            $counts[$state]++;
        }
        return $counts;
    }
}
