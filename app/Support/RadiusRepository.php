<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

final class RadiusRepository
{
    private static ?\Closure $routerSocketFactory = null;
    private static float $routerDeadline = 0;

    private const EXPIRED_SERVICE_GROUP = 'expired';

    private const MANAGED_CHECK = [
        'Cleartext-Password', 'Auth-Type', 'Simultaneous-Use', 'Calling-Station-Id',
        'NAS-IP-Address', 'NAS-Identifier', 'Expiration', 'Framed-Protocol', 'Service-Type',
    ];

    private const MANAGED_REPLY = [
        'Framed-Protocol', 'Service-Type', 'Framed-IP-Address', 'Framed-IP-Netmask',
        'Framed-Pool', 'Mikrotik-Rate-Limit', 'Session-Timeout', 'Idle-Timeout',
    ];

    /** Pure normalization shared by the controller's recent-auth gate and provisioning. */
    public static function normalizeClientIdentity(array $input): array
    {
        $authMethod = self::normalizeAuthMethod($input['auth_method'] ?? 'pppoe');
        $input['auth_method'] = $authMethod;
        if ($authMethod === 'dhcp') {
            $input['mac_address'] = self::normalizeMacAddress((string) ($input['mac_address'] ?? ''));
            if ($input['mac_address'] === '') {
                throw new RuntimeException('MAC address is required for DHCP authentication.');
            }
            if (!preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $input['mac_address'])) {
                throw new RuntimeException('Enter a valid MAC address for DHCP authentication.');
            }
            $input['username'] = $input['mac_address'];
            $input['mac_lock'] = '0'; // DHCP Calling-Station-Id is a client identifier, not a MAC.
        }

        $input['username'] = trim((string) ($input['username'] ?? ''));
        if ($input['username'] === '' || strlen($input['username'])>64 || preg_match('/[\x00-\x20\x7f]/', $input['username'])) {
            throw new RuntimeException($authMethod === 'dhcp' ? 'MAC address is required.' : 'Username is required.');
        }
        return $input;
    }

    public static function saveClient(array $input, ?string $oldUsername = null): string
    {
        SchemaCompatibility::require('policy');
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $input = self::normalizeClientIdentity($input);
            $authMethod = $input['auth_method'];
            $username = $input['username'];
            if ($authMethod === 'pppoe' && trim((string) ($input['password'] ?? '')) === '' && !self::existingPasswordFor($oldUsername ?: $username)) {
                throw new RuntimeException('PPPoE password is required.');
            }

            $profile = Db::row('SELECT id,account_status,expiry_date FROM customer_profiles WHERE username = ? FOR UPDATE', [$oldUsername ?: $username]);
            if ($profile && $profile['account_status'] === 'archived') {
                throw new RuntimeException('Archived customers cannot be provisioned or renamed.');
            }

            self::assertIdentityAvailable($username, $oldUsername);
            if ($oldUsername && $oldUsername !== $username) {
                self::renameRadiusUser($oldUsername, $username);
            }

            $exists = Db::value(
                'SELECT COUNT(*) FROM customer_profiles WHERE username = ? AND (? IS NULL OR username <> ?)',
                [$username, $oldUsername, $oldUsername]
            );
            if ((int) $exists > 0) {
                throw new RuntimeException('Username already exists.');
            }
            self::normalizeAccountStatus($input);

            $serviceGroup = trim((string) ($input['service_group'] ?? ''));
            if ($serviceGroup === '' || !Db::row('SELECT group_name FROM service_metadata WHERE group_name=? AND enabled=1 FOR UPDATE',[$serviceGroup])) {
                throw new RuntimeException('Select an enabled service plan for this client.');
            }
            $input['custom_radius_attributes'] = '';
            self::normalizeClientIp($input, $oldUsername);

            self::upsertProfile($input, $oldUsername);
            SubscriberPolicy::bind(Db::row('SELECT * FROM customer_profiles WHERE username=?',[$username]),$input);
            BillingRepository::refreshCustomerBalance($username);
            self::provisionClient($username, (string) ($input["password"] ?? ""));

            $saved = Db::row('SELECT id,expiry_date FROM customer_profiles WHERE username=?', [$username]);
            if (!$profile) CustomerNotifications::enrolled((int)$saved['id']);
            else CustomerNotifications::extended((int)$saved['id'], $profile['expiry_date'], $saved['expiry_date']);
            $pdo->commit();SubscriberPolicy::releaseLocks();
            return $username;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } catch (\Throwable) { /* Preserve the original failure. */ }
            }
            throw $e;
        }
    }

    /** Retain customer identity and history while atomically removing authentication provisioning. */
    public static function archiveClient(string $username): void
    {
        SchemaCompatibility::require('policy');
        // This deployment migration is optional for application archival; never create it at runtime.
        $archiveDate = Db::value("SHOW COLUMNS FROM customer_profiles LIKE 'archived_at'") ? 'archived_at=NOW(), ' : '';
        SchemaCompatibility::require('policy');
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $client = Db::row('SELECT username, account_status FROM customer_profiles WHERE username=? FOR UPDATE', [$username]);
            if (!$client) { throw new RuntimeException('Client not found.'); }
            if ($client['account_status'] !== 'archived') {
                foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
                    Db::exec("DELETE FROM {$table} WHERE username=?", [$username]);
                }
                // Keep an explicit rejection so fallback/unmanaged authentication cannot revive this identity.
                Db::exec('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, "Auth-Type", ":=", "Reject")', [$username]);
                Db::exec('UPDATE customer_profiles SET ' . $archiveDate . 'enabled=0, account_status="archived", updated_at=NOW() WHERE username=?', [$username]);
                $archived=Db::row('SELECT * FROM customer_profiles WHERE username=?',[$username]);
                SubscriberPolicy::publish($archived,(string)$archived['service_group']);
            }
            $pdo->commit();SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } catch (\Throwable) { /* Preserve the original failure. */ }
            }
            throw $e;
        }
    }

    public static function saveService(array $input, ?int $id = null): int
    {
        SchemaCompatibility::require('policy');
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $group = trim($input['group_name']);
            if ($group === '') {
                throw new RuntimeException('RADIUS group name is required.');
            }
            $current = $id ? Db::value('SELECT group_name FROM service_metadata WHERE id=?',[$id]) : null;
            if($current!==$group){
                foreach(['service_metadata'=>'group_name','radgroupcheck'=>'groupname','radgroupreply'=>'groupname'] as $table=>$column){
                    if(Db::row("SELECT $column FROM $table WHERE $column=? LIMIT 1 FOR UPDATE",[$group])){throw new RuntimeException('Service name collides with managed or unmanaged RADIUS group.');}
                }
            }
            $expiredGroup = trim((string) ($input['expired_service_group'] ?? ''));
            if ($expiredGroup !== '' && !self::serviceIsEnabled($expiredGroup, $id)) {
                throw new RuntimeException('Select an enabled expired service, or leave it blank.');
            }
            $download=\App\Support\Policy\Adapter::bps($input['download_speed']??null);
            $upload=\App\Support\Policy\Adapter::bps($input['upload_speed']??null);
            if(($download===null)!==($upload===null)){throw new RuntimeException('Supply both download and upload speeds.');}
            $input['mikrotik_rate_limit']=$download===null?'':$upload.'/'.$download;
            self::assertNoManagedConflicts((string) ($input['custom_group_attrs'] ?? ''), array_merge(self::MANAGED_CHECK, self::MANAGED_REPLY));

            if ($id) {
                $before = Db::row('SELECT * FROM service_metadata WHERE id = ? FOR UPDATE', [$id]);
                if (!$before) {
                    throw new RuntimeException('Service not found.');
                }
                $oldGroup = $before['group_name'];
                if (empty($input['enabled'])) { self::assertServiceUnreferenced($oldGroup); }
                Db::exec(
                    'UPDATE service_metadata SET group_name=?, name=?, description=?, enabled=?, download_speed=?, upload_speed=?, mikrotik_rate_limit=?, burst_limit=?, burst_threshold=?, burst_time=?, priority=?, ip_mode=?, ip_pool=?, static_ip_pool=?, session_timeout=?, idle_timeout=?, simultaneous_use=?, download_quota=?, upload_quota=?, total_quota=?, expiration_behavior=?, billing_mode=?, expired_service_group=?, price=?, currency=?, billing_period=?, custom_group_attrs=?, updated_at=NOW() WHERE id=?',
                    self::serviceParams($input, [$id])
                );
                if ($oldGroup !== $group) {
                    foreach (['customer_profiles', 'billing_invoices', 'billing_invoice_items'] as $table) {
                        Db::exec("UPDATE $table SET service_group=? WHERE service_group=?", [$group, $oldGroup]);
                    }
                    Db::exec('UPDATE radusergroup SET groupname = ? WHERE groupname = ?', [$group, $oldGroup]);
                    Db::exec('UPDATE service_metadata SET expired_service_group = ? WHERE expired_service_group = ?', [$group, $oldGroup]);
                    Db::exec('DELETE FROM radgroupcheck WHERE groupname = ?', [$oldGroup]);
                    Db::exec('DELETE FROM radgroupreply WHERE groupname = ?', [$oldGroup]);
                }
            } else {
                Db::exec(
                    'INSERT INTO service_metadata (group_name, name, description, type, enabled, download_speed, upload_speed, mikrotik_rate_limit, burst_limit, burst_threshold, burst_time, priority, ip_mode, ip_pool, static_ip_pool, session_timeout, idle_timeout, simultaneous_use, download_quota, upload_quota, total_quota, expiration_behavior, billing_mode, expired_service_group, price, currency, billing_period, custom_group_attrs, created_at, updated_at)
                     VALUES (?, ?, ?, "PPPoE", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    self::serviceParams($input)
                );
                $id = (int) $pdo->lastInsertId();
            }

            self::replaceGroupAttributes($group, $input);
            Db::exec('UPDATE service_metadata SET policy_revision=policy_revision+1 WHERE id=?',[$id]);
            Db::exec('UPDATE subscriptions u JOIN customer_profiles c ON c.id=u.customer_id SET u.dirty=1 WHERE c.service_group=? OR c.service_group IN (SELECT group_name FROM service_metadata WHERE expired_service_group=?) OR ?="expired"',[$group,$group,$group]);
            $pdo->commit();SubscriberPolicy::releaseLocks();
            return (int) $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } catch (\Throwable) { /* Preserve the original failure. */ }
            }
            throw $e;
        }
    }

    public static function saveNas(array $input, ?int $id = null): int
    {
        return self::withNasLock($id??0,static fn()=>self::saveNasLocked($input,$id));
    }
    /** Serialize NAS configuration changes with worker observation and application. */
    private static function withNasLock(int $id,callable $work): mixed
    {
        $lock='frweb:network:'.substr(hash('sha256',(string)Db::value('SELECT DATABASE()')),0,24).':'.$id;
        if((int)Db::value('SELECT GET_LOCK(?,0)',[$lock])!==1){throw new RuntimeException('A network operation is active on this NAS; retry the change shortly.');}
        try{return $work();}finally{Db::value('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private static function saveNasLocked(array $input, ?int $id = null): int
    {
        $vendor = trim((string) ($input['vendor'] ?? ''));
        if ($vendor === '') {
            $vendor = trim((string) ($input['type'] ?? ''));
        }
        $isMikrotik = strtolower($vendor) === 'mikrotik';
        $coaEnabled = self::boolValue($input['coa_enabled'] ?? null);
        $apiEnabled = $isMikrotik ? self::boolValue($input['api_enabled'] ?? null) : 0;
        SchemaCompatibility::require('policy');
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            // Only what FreeRADIUS reads (the client list) needs a RADIUS update; CoA and API
            // settings are used by the portal alone and apply at once.
            $radiusChanged = true;
            if ($id) {
                $old = Db::row('SELECT n.nasname, n.shortname, n.type, n.secret, COALESCE(m.enabled, 1) AS enabled FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id WHERE n.id=? FOR UPDATE', [$id]);
                $radiusChanged = !$old || (string) $old['nasname'] !== (string) $input['nasname'] || (string) $old['shortname'] !== (string) $input['shortname']
                    || (string) $old['type'] !== (string) $input['type'] || (($input['secret'] ?? '') !== '' && !hash_equals((string) $old['secret'], (string) $input['secret']))
                    || (int) $old['enabled'] !== self::boolValue($input['enabled'] ?? null);
                Db::exec(
                    'UPDATE nas SET nasname=?, shortname=?, type=?, ports=?, secret=IF(? = "", secret, ?), server=?, community=?, description=? WHERE id=?',
                    [
                        $input['nasname'], $input['shortname'], $input['type'], (int) ($input['ports'] ?: 0),
                        $input['secret'] ?? '', $input['secret'] ?? '', $input['server'], $input['community'], $input['description'], $id,
                    ]
                );
            } else {
                Db::exec(
                    'INSERT INTO nas (nasname, shortname, type, ports, secret, server, community, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$input['nasname'], $input['shortname'], $input['type'], (int) ($input['ports'] ?: 0), $input['secret'], $input['server'], $input['community'], $input['description']]
                );
                $id = (int) $pdo->lastInsertId();
            }

            Db::exec(
                'INSERT INTO nas_metadata (nas_id, vendor, enabled, coa_enabled, coa_port, api_host, api_user, api_password, updated_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = "", NULL, ?), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE vendor=VALUES(vendor), enabled=VALUES(enabled), coa_enabled=VALUES(coa_enabled), coa_port=VALUES(coa_port), api_host=VALUES(api_host), api_user=VALUES(api_user), api_password=COALESCE(VALUES(api_password), api_password), config_revision=config_revision+?, pending_delete=0, updated_at=NOW()',
                [
                    $id, $input['vendor'], self::boolValue($input['enabled'] ?? null), $coaEnabled,
                    (int) ($input['coa_port'] ?: 3799), $input['api_host'], $input['api_user'],
                    Secrets::encrypt((string) ($input['api_password'] ?? '')), Secrets::encrypt((string) ($input['api_password'] ?? '')),
                    $radiusChanged ? 1 : 0,
                ]
            );

            if (self::hasApiSwitch()) {
                Db::exec('UPDATE nas_metadata SET api_enabled=? WHERE nas_id=?', [$apiEnabled, $id]);
            }

            $pdo->commit();SubscriberPolicy::releaseLocks();
            return (int) $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } catch (\Throwable) { /* Preserve the original failure. */ }
            }
            throw $e;
        }
    }

    /** Caller owns the transaction; all transitions lock the profile before changing provisioning. */
    public static function provisionClient(string $username, string $password = ''): void
    {
        if (!Db::pdo()->inTransaction()) { throw new RuntimeException('Provisioning requires a transaction.'); }
        $client = Db::row('SELECT * FROM customer_profiles WHERE username=? FOR UPDATE', [$username]);
        if (!$client || $client['account_status'] === 'archived') { return; }
        $client['password'] = $password;
        $expired = self::isExpiredAccount($client);
        if (!in_array($client['account_status'], ['suspended', 'disabled'], true)) {
            $client['account_status'] = $expired ? 'expired' : 'active';
            Db::exec('UPDATE customer_profiles SET account_status=? WHERE username=?', [$client['account_status'], $username]);
        }
        self::replaceUserChecks($username, $client);
        self::replaceUserReplies($username, $client);
        $effectiveGroup=self::radiusServiceGroup($client, (string)$client['service_group']);
        self::replaceUserGroup($username,$effectiveGroup);
        SubscriberPolicy::publish($client,$effectiveGroup);
    }

    public static function setClientStatus(string $username, string $status): void
    {
        SchemaCompatibility::require('policy');
        if (!in_array($status, ['disabled', 'suspended'], true)) { throw new RuntimeException('Invalid status.'); }
        SchemaCompatibility::require('policy');
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $row = Db::row('SELECT account_status FROM customer_profiles WHERE username=? FOR UPDATE', [$username]);
            if (!$row || $row['account_status'] === 'archived') { throw new RuntimeException('Client not found or archived.'); }
            Db::exec('UPDATE customer_profiles SET enabled=0, account_status=?, updated_at=NOW() WHERE username=?', [$status, $username]);
            self::provisionClient($username);
            $pdo->commit();SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } throw $e; }
    }

    public static function syncExpiredServiceGroups(): int
    {
        return SubscriberPolicy::due();
    }

    public static function deleteService(int $id): void
    {
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            $service = Db::row('SELECT group_name FROM service_metadata WHERE id=? FOR UPDATE', [$id]);
            if ($service) {
                self::assertServiceUnreferenced((string) $service['group_name']);
                foreach (['radgroupcheck', 'radgroupreply'] as $table) { Db::exec("DELETE FROM $table WHERE groupname=?", [$service['group_name']]); }
                Db::exec('DELETE FROM service_metadata WHERE id=?', [$id]);
            }
            $pdo->commit();SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack();SubscriberPolicy::releaseLocks(); } throw $e; }
    }

    private static function assertServiceUnreferenced(string $group): void
    {
        foreach (['customer_profiles'=>'service_group', 'billing_invoices'=>'service_group', 'billing_invoice_items'=>'service_group', 'service_metadata'=>'expired_service_group', 'radusergroup'=>'groupname'] as $table=>$column) {
            if (Db::value("SELECT COUNT(*) FROM $table WHERE $column=?", [$group])) {
                throw new RuntimeException('Referenced services cannot be deleted or disabled.');
            }
        }
    }

    public static function deleteNas(int $id): void
    {
        self::withNasLock($id??0,static fn()=>self::deleteNasLocked($id));
    }
    private static function deleteNasLocked(int $id): void
    {
        $pdo=Db::pdo();$pdo->beginTransaction();
        try {
            if (!Db::row('SELECT id FROM nas WHERE id=? FOR UPDATE',[$id])) { throw new RuntimeException('NAS not found.'); }
            Db::exec('INSERT INTO nas_metadata (nas_id,vendor,enabled,coa_enabled,created_at,updated_at,pending_delete) VALUES (?,"generic",0,0,NOW(),NOW(),1) ON DUPLICATE KEY UPDATE enabled=0,pending_delete=1,config_revision=config_revision+1,updated_at=NOW()',[$id]);
            $pdo->commit();SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) { if($pdo->inTransaction()){$pdo->rollBack();SubscriberPolicy::releaseLocks();}throw $e; }
    }

    /** Records operator-supplied deployment evidence, never activates/restarts RADIUS. */
    public static function markNasApplied(int $id, int $revision, string $evidence): void
    {
        self::withNasLock($id??0,static fn()=>self::markNasAppliedLocked($id,$revision,$evidence));
    }
    private static function markNasAppliedLocked(int $id, int $revision, string $evidence): void
    {
        if (trim($evidence)==='' || strlen($evidence)>500) { throw new RuntimeException('Deployment evidence is required.'); }
        $pdo=Db::pdo();$pdo->beginTransaction();
        try{
            $row=Db::row('SELECT * FROM nas_metadata WHERE nas_id=? FOR UPDATE',[$id]);
            if(!$row || (int)$row['config_revision']!==$revision){throw new RuntimeException('NAS revision changed; verify deployment again.');}
            if((int)$row['applied_revision']!==$revision || $row['applied_evidence']!==$evidence){
                Db::exec('UPDATE nas_metadata SET applied_revision=?,applied_at=NOW(),applied_evidence=? WHERE nas_id=?',[$revision,$evidence,$id]);
            }
            $pdo->commit();SubscriberPolicy::releaseLocks();
        }catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();SubscriberPolicy::releaseLocks();}throw $e;}
    }

    /**
     * FreeRADIUS reads the NAS list (client_query) only when it starts and refuses to start with a broken
     * one. So while it is running, every NAS saved before it started is in effect: record those revisions,
     * including removals. Runs every minute (frweb:mark-nas-applied --auto).
     * @param int|null $radiusStartedAt epoch FreeRADIUS became active; read from systemd when null
     * @return array<int, int> nas id => revision recorded
     */
    public static function recordLoadedNasRevisions(?int $radiusStartedAt = null): array
    {
        if ($radiusStartedAt === null) {
            $radius = ServerMonitor::services()['freeradius'] ?? null;
            if (!$radius || $radius['state'] !== 'active' || !$radius['since']) {
                return [];
            }
            $radiusStartedAt = (int) $radius['since'];
        }
        $recorded = [];
        $rows = Db::select('SELECT nas_id, config_revision FROM nas_metadata WHERE (applied_revision IS NULL OR applied_revision <> config_revision) AND updated_at < FROM_UNIXTIME(?)', [$radiusStartedAt]);
        foreach ($rows as $row) {
            $revision = (int) $row['config_revision'];
            try {
                self::markNasApplied((int) $row['nas_id'], $revision, 'FreeRADIUS started ' . date('Y-m-d H:i', $radiusStartedAt) . ', after revision ' . $revision . ' was saved (recorded automatically).');
                $recorded[(int) $row['nas_id']] = $revision;
            } catch (RuntimeException) {
                // Saved again meanwhile: the next restart picks it up.
            }
        }
        return $recorded;
    }

    /** Exactly one accounting identity, optionally explicitly selected. No discovery fallback. */
    public static function disconnectUser(string $username, ?int $nasId = null, ?int $accountingId = null): array
    {
        $method=Db::value('SELECT auth_method FROM customer_profiles WHERE username=?',[$username]);
        if($method==='dhcp'){throw new RuntimeException('DHCP requires a validated forwarding/lease adapter; PPP removal is forbidden.');}
        $session = Db::row(
            'SELECT r.*, n.id AS nas_id, n.secret, n.nasname, n.type AS nas_type,
                    m.coa_enabled,m.coa_port,COALESCE(m.vendor,n.type) AS vendor,m.api_host,m.api_user,m.api_password,' . self::apiEnabledSql('m') . ' AS api_enabled,m.enabled,m.config_revision,m.applied_revision,m.pending_delete
             FROM radacct r JOIN nas n ON n.nasname=r.nasipaddress JOIN nas_metadata m ON m.nas_id=n.id
             WHERE ' . ClientQuery::inUsernames('r.username', $ids = ClientQuery::usernames($username)) . ' AND r.acctstoptime IS NULL AND (? IS NULL OR n.id=?) AND (? IS NULL OR r.radacctid=?)
             ORDER BY r.acctstarttime DESC,r.radacctid DESC LIMIT 1',array_merge($ids,[$nasId,$nasId,$accountingId,$accountingId]));
        if (!$session || empty($session['acctsessionid'])) { throw new RuntimeException('No identified active session found in this NAS scope.'); }
        if (empty($session['enabled']) || !empty($session['pending_delete']) || $session['applied_revision']===null || (int)$session['applied_revision']!==(int)$session['config_revision']) { throw new RuntimeException('NAS configuration is disabled or pending deployment.'); }
        $updated=strtotime((string)($session['acctupdatetime'] ?: $session['acctstarttime']));
        if (!$updated || $updated < time()-Accounting::window($session) || $updated > time()+60) { throw new RuntimeException('Accounting is stale; verify the session in a lab/operational workflow before disconnect.'); }
        $method = self::disconnectMethod($session)['method'];
        if ($method === 'api') {
            $output=self::disconnectUserViaMikrotikApi($session,(string)$session['username']);
            if($output===null){throw new RuntimeException('MikroTik API credentials are required.');}
            return ['command'=>'mikrotik-api-tls','output'=>$output];
        }
        if ($method !== 'coa') { throw new RuntimeException('No disconnect method is set up for this NAS: turn on RADIUS disconnect (CoA) on its Device tab, or the RouterOS API on its API tab.'); }
        $attrs=['User-Name'=>$session['username'],'Acct-Session-Id'=>$session['acctsessionid'],'NAS-IP-Address'=>$session['nasipaddress']];
        $payload='';
        foreach($attrs as $attribute=>$value){
            if(preg_match('/[\x00-\x1f\x7f]/',(string)$value)){throw new RuntimeException('Invalid disconnect identity.');}
            $payload.=$attribute.' = "'.addcslashes((string)$value,"\\\"").'"'."\n";
        }
        $payload.="Message-Authenticator = 0x00\n";
        $dir=(string)App::env('SECURITY_STORAGE',App::root('storage/security'));
        if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)){throw new RuntimeException('Private secret storage unavailable.');}
        $file=tempnam($dir,'disconnect-');
        if($file===false){throw new RuntimeException('Private secret file unavailable.');}
        try {
            chmod($file,0600);
            if(file_put_contents($file,(string)$session['secret']."\n")===false){throw new RuntimeException('Secret file write failed.');}
            $result=BoundedProcess::run([(string)App::env('RADCLIENT_BIN','/usr/bin/radclient'),'-r','1','-t','2','-S',$file,$session['nasipaddress'].':'.(int)$session['coa_port'],'disconnect'],$payload,8);
            if($result['exit']!==0 || !preg_match('/\bDisconnect-ACK\b/',$result['output'])){throw new RuntimeException('Disconnect not acknowledged; verify the same session before retrying.');}
            return ['command'=>'radclient-disconnect','output'=>'The NAS acknowledged the disconnect (Disconnect-ACK).'];
        } finally { unlink($file); }
    }

    /**
     * RouterOS 7 lists a PPP session as "0x81000000" while its accounting carries Acct-Session-Id
     * "81000000": the same session. Compare without the prefix and case; an empty id never matches.
     */
    private static function sameSessionId(string $router, string $accounting): bool
    {
        $norm = static fn (string $id): string => strtolower(preg_replace('/^0x/i', '', trim($id)) ?? '');
        return $norm($router) !== '' && $norm($router) === $norm($accounting);
    }

    /**
     * How the portal ends a session on this NAS: the RouterOS API when it is switched on (MikroTik
     * only) and has a login, otherwise a RADIUS Disconnect-Request (CoA) signed with the shared
     * secret, like most RADIUS managers. Takes a NAS row; secret may be named secret or nas_secret.
     * @return array{method:?string,label:string,ok:bool,class:string,note:string}
     */
    public static function disconnectMethod(array $nas): array
    {
        $vendor = strtolower(trim((string) (($nas['vendor'] ?? '') ?: ($nas['nas_vendor'] ?? '') ?: ($nas['nas_type'] ?? '') ?: ($nas['type'] ?? ''))));
        if ($vendor === 'mikrotik' && !empty($nas['api_enabled'])) {
            return self::mikrotikDisconnectApiStatus($nas)['ok']
                ? ['method' => 'api', 'label' => 'API ready', 'ok' => true, 'class' => 'success', 'note' => 'RouterOS API']
                : ['method' => null, 'label' => 'API login missing', 'ok' => false, 'class' => 'warning', 'note' => 'RouterOS API'];
        }
        $secret = (string) (($nas['secret'] ?? '') ?: ($nas['nas_secret'] ?? ''));
        $hasSecret = $secret !== '' || !empty($nas['secret_saved']);
        if ((int) ($nas['coa_enabled'] ?? 0) === 1 && $hasSecret) {
            return ['method' => 'coa', 'label' => 'CoA ready', 'ok' => true, 'class' => 'success', 'note' => 'RADIUS disconnect (CoA) on UDP ' . (int) (($nas['coa_port'] ?? 0) ?: 3799)];
        }
        return ['method' => null, 'label' => 'off', 'ok' => false, 'class' => 'warning', 'note' => 'Turn on CoA (Device tab) or the API'];
    }

    /** Whether migration 028 (nas_metadata.api_enabled) is applied. */
    public static function hasApiSwitch(): bool
    {
        static $has = null;
        return $has ??= (bool) Db::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nas_metadata' AND COLUMN_NAME = 'api_enabled'");
    }

    /** SQL for the API switch; before migration 028 a saved API login counts as switched on (the old behaviour). */
    public static function apiEnabledSql(string $alias): string
    {
        return self::hasApiSwitch()
            ? "COALESCE($alias.api_enabled, 0)"
            : "(COALESCE($alias.api_user, '') <> '' AND COALESCE($alias.api_password, '') <> '')";
    }

    public static function mikrotikDisconnectApiStatus(array $nas): array
    {
        $host = trim((string) (($nas['api_host'] ?? '') ?: ($nas['nasname'] ?? '')));
        $user = trim((string) ($nas['api_user'] ?? ''));
        $passwordSaved = !empty($nas['api_password']) || !empty($nas['api_password_saved']);
        if ($host === '' || $user === '' || !$passwordSaved) {
            return ['ok' => false, 'label' => 'not configured', 'class' => 'warning'];
        }

        return ['ok' => true, 'label' => 'configured', 'class' => 'success'];
    }

    private static function routerUptimeSeconds(string $uptime): int
    {
        preg_match_all('/(\d+)(w|d|h|m|s)/', $uptime, $matches, PREG_SET_ORDER);
        $seconds = 0;
        foreach ($matches as $match) {
            $value = (int) $match[1];
            $seconds += match ($match[2]) {
                'w' => $value * 604800,
                'd' => $value * 86400,
                'h' => $value * 3600,
                'm' => $value * 60,
                's' => $value,
            };
        }
        return $seconds;
    }

    private static function disconnectUserViaMikrotikApi(array $session, string $username): ?string
    {
        $host = trim((string) ($session['api_host'] ?: $session['nasname'] ?: $session['nasipaddress'] ?: ''));
        $user = trim((string) ($session['api_user'] ?? ''));
        $password = Secrets::reveal($session['api_password'] ?? '');
        if ($host === '' || $user === '' || $password === '') {
            return null;
        }

        $socket = self::routerApiConnect($host);

        try {
            self::routerApiWriteSentence($socket, ['/login', '=name=' . $user, '=password=' . $password]);
            self::routerApiAssertOk(self::routerApiReadReply($socket), 'MikroTik API login failed');

            $rows = self::routerApiFindActivePpp($socket, 'name', $username);
            $matches = array_values(array_filter($rows, function(array $row) use ($session,$username): bool {
                return ($row['name'] ?? '') === $username && self::sameSessionId((string)($row['session-id'] ?? ''), (string)$session['acctsessionid'])
                    && !empty($row['.id']) && (empty($session['callingstationid']) || ($row['caller-id'] ?? '')===$session['callingstationid']);
            }));
            if(count($matches)!==1){throw new RuntimeException('NAS did not verify exactly one matching username/session identity.');}
            // Re-read that handle immediately before removal. A reused address never supplies identity.
            $verified=self::routerApiFindActivePpp($socket,'.id',(string)$matches[0]['.id']);
            if(count($verified)!==1 || ($verified[0]['name'] ?? '')!==$username || !self::sameSessionId((string)($verified[0]['session-id'] ?? ''), (string)$session['acctsessionid'])) { throw new RuntimeException('NAS session changed before removal.'); }
            self::routerApiWriteSentence($socket,['/ppp/active/remove','=.id='.$matches[0]['.id']]);
            self::routerApiAssertOk(self::routerApiReadReply($socket),'MikroTik API remove failed');
            return 'MikroTik API removed one verified PPP session; awaiting accounting reconciliation.';
        } finally {
            fclose($socket);
        }
    }

    private static function routerApiFindActivePpp($socket, string $field, string $value): array
    {
        self::routerApiWriteSentence($socket, ['/ppp/active/print', '?' . $field . '=' . $value]);
        $reply = self::routerApiReadReply($socket);
        self::routerApiAssertOk($reply, 'MikroTik API active session lookup failed');
        $rows = [];
        foreach ($reply as $sentence) {
            if (($sentence[0] ?? '') !== '!re') {
                continue;
            }
            $rows[] = self::routerApiParseSentence($sentence);
        }
        return $rows;
    }

    private static function routerApiConnect(string $host)
    {
        $url=parse_url(str_contains($host,'://')?$host:'tls://'.$host);
        if(!$url || ($url['scheme'] ?? '')!=='tls' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['path']) || isset($url['query']) || isset($url['fragment'])){throw new RuntimeException('Use a TLS API host with a trusted certificate.');}
        $port=(int)($url['port'] ?? 8729);
        if($port<1 || $port>65535){throw new RuntimeException('Invalid API port.');}
        $options=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$url['host']]];
        self::$routerDeadline=microtime(true)+8;
        $endpoint='tls://'.$url['host'].':'.$port;
        $socket=self::$routerSocketFactory ? (self::$routerSocketFactory)($endpoint,$options) : @stream_socket_client($endpoint,$errno,$error,4,STREAM_CLIENT_CONNECT,stream_context_create($options));
        if(!is_resource($socket)){throw new RuntimeException('Verified TLS API connection failed.');}
        return $socket;
    }

    private static function routerApiDeadline($socket): void
    {
        $remaining=self::$routerDeadline-microtime(true);
        if($remaining<=0){throw new RuntimeException('NAS operation timed out; verify the same session before retrying.');}
        stream_set_timeout($socket,(int)$remaining,(int)(($remaining-(int)$remaining)*1000000));
    }

    private static function routerApiReadBytes($socket,int $length): string
    {
        if($length<0 || $length>65536){throw new RuntimeException('NAS reply exceeds allowed size.');}
        $bytes='';
        while(strlen($bytes)<$length){
            self::routerApiDeadline($socket);
            $chunk=fread($socket,$length-strlen($bytes));
            if($chunk===false || $chunk===''){throw new RuntimeException('NAS reply incomplete or timed out; verify before retrying.');}
            $bytes.=$chunk;
        }
        return $bytes;
    }

    private static function routerApiWriteSentence($socket, array $words): void
    {
        foreach ($words as $word) {
            self::routerApiWriteWord($socket, $word);
        }
        self::routerApiWriteWord($socket, '');
    }

    private static function routerApiWriteWord($socket, string $word): void
    {
        $bytes=self::routerApiEncodeLength(strlen($word)).$word;
        while($bytes!==''){ self::routerApiDeadline($socket); $n=fwrite($socket,$bytes); if(!$n){throw new RuntimeException('NAS write failed.');} $bytes=substr($bytes,$n); }
    }

    private static function routerApiReadReply($socket): array
    {
        $reply = [];
        while (true) {
            $sentence = self::routerApiReadSentence($socket);
            if ($sentence === null) {
                throw new RuntimeException('MikroTik API connection closed before a reply was received.');
            }
            if (!$sentence) {
                continue;
            }
            $reply[] = $sentence;
            if(count($reply)>256){throw new RuntimeException('Too many NAS reply sentences.');}
            if (in_array('!done', $sentence, true) || in_array('!fatal', $sentence, true)) {
                return $reply;
            }
        }
    }

    private static function routerApiReadSentence($socket): ?array
    {
        $sentence = [];
        while (true) {
            $word = self::routerApiReadWord($socket);
            if ($word === null) {
                return $sentence ?: null;
            }
            if ($word === '') {
                return $sentence;
            }
            $sentence[] = $word;
            if(count($sentence)>64){throw new RuntimeException('Too many NAS reply words.');}
        }
    }

    private static function routerApiReadWord($socket): ?string
    {
        $c=ord(self::routerApiReadBytes($socket,1));
        if(($c & 0x80)===0){$length=$c;}
        elseif(($c & 0xc0)===0x80){$length=(($c & 0x3f)<<8)+ord(self::routerApiReadBytes($socket,1));}
        elseif(($c & 0xe0)===0xc0){$b=self::routerApiReadBytes($socket,2);$length=(($c & 0x1f)<<16)+(ord($b[0])<<8)+ord($b[1]);}
        elseif(($c & 0xf0)===0xe0){$b=self::routerApiReadBytes($socket,3);$length=(($c & 0xf)<<24)+(ord($b[0])<<16)+(ord($b[1])<<8)+ord($b[2]);}
        elseif($c===0xf0){$length=unpack('N',self::routerApiReadBytes($socket,4))[1];}
        else{throw new RuntimeException('Invalid NAS word length.');}
        return self::routerApiReadBytes($socket,$length);
    }

    private static function routerApiEncodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            return chr(($length >> 8) | 0x80) . chr($length & 0xFF);
        }
        if ($length < 0x200000) {
            return chr(($length >> 16) | 0xC0) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x10000000) {
            return chr(($length >> 24) | 0xE0) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    private static function routerApiAssertOk(array $reply, string $message): void
    {
        foreach ($reply as $sentence) {
            if (in_array('!trap', $sentence, true) || in_array('!fatal', $sentence, true)) {
                $row = self::routerApiParseSentence($sentence);
                throw new RuntimeException($message . ': ' . ($row['message'] ?? 'router returned an error'));
            }
        }
    }

    private static function routerApiParseSentence(array $sentence): array
    {
        $row = [];
        foreach ($sentence as $word) {
            if (!str_starts_with($word, '=') || !str_contains(substr($word, 1), '=')) {
                continue;
            }
            [$key, $value] = explode('=', substr($word, 1), 2);
            $row[$key] = $value;
        }
        return $row;
    }

    private static function disconnectFailureMessage(array $session, string $output): string
    {
        if (str_contains($output, 'No reply from server')) {
            return 'NAS did not reply to disconnect on ' . $session['nasipaddress'] . ':' . ($session['coa_port'] ?: 3799)
                . '. Check RouterOS /radius incoming, UDP 3799 firewall, RADIUS secret, and source IP. Or save MikroTik API credentials on the NAS record.';
        }

        return 'Disconnect failed: ' . trim($output);
    }

    private static function assertIdentityAvailable(string $username, ?string $old): void
    {
        if($username===$old){return;}
        if(Db::row('SELECT username FROM customer_identity_history WHERE username=? FOR UPDATE',[$username])) { throw new RuntimeException('Username is reserved by historical accounting identity.'); }
        foreach(['customer_profiles','radcheck','radreply','radusergroup','radacct','radpostauth'] as $table){
            if(Db::row("SELECT username FROM $table WHERE username=? LIMIT 1 FOR UPDATE",[$username])){throw new RuntimeException('Username already exists in managed or unmanaged RADIUS/history records.');}
        }
        if($old){
            $profile=Db::row('SELECT id FROM customer_profiles WHERE username=? FOR UPDATE',[$old]);
            if(!$profile){throw new RuntimeException('Client not found.');}
            foreach(['billing_invoices','billing_payments','uploaded_documents','notification_logs'] as $table){
                if(Db::value("SELECT COUNT(*) FROM $table WHERE username=?",[$old]) && !Db::value('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME="customer_profiles" AND UPDATE_RULE="CASCADE"',[$table])) {
                    throw new RuntimeException('Identity reference migration is required before renaming this customer.');
                }
            }
        }
    }

    private static function renameRadiusUser(string $old, string $new): void
    {
        $id=Db::value('SELECT id FROM customer_profiles WHERE username=?',[$old]);
        Db::exec('INSERT INTO customer_identity_history (username,customer_id,renamed_at) VALUES (?,?,NOW())',[$old,$id]);
        foreach(['radcheck','radreply','radusergroup'] as $table){Db::exec("UPDATE $table SET username=? WHERE username=?",[$new,$old]);}
        // Historical events are immutable. Reserve and reject the former network identity.
        Db::exec('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,"Auth-Type",":=","Reject")',[$old]);
    }

    private static function upsertProfile(array $input, ?string $oldUsername): void
    {
        $fields = [
            'username','auth_method','enabled','verified','email_alert','sms_alert','account_status','expiry_date','available_online_time','simultaneous_use',
            'service_group','owner_operator_id','ip_mode','framed_ip_address','framed_pool','mac_address','mac_lock','nas_restriction_type','nas_restriction_value',
            'first_name','last_name','company_name','address','city','state','zip','country','phone','cell_phone','email','vat_tax_id','contract_id','contract_valid_until','gps','language','notes',
            'download_limit','upload_limit','total_traffic_limit','balance','invoice_alert','payment_status','billing_mode','custom_radius_attributes',
        ];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $input[$field] ?? null;
        }
        foreach (['enabled','verified','email_alert','sms_alert','mac_lock','invoice_alert'] as $bool) {
            $data[$bool] = self::boolValue($input[$bool] ?? null);
        }
        foreach (['available_online_time', 'simultaneous_use', 'owner_operator_id'] as $integer) {
            $data[$integer] = self::intOrNull($data[$integer]);
        }
        foreach (['expiry_date', 'contract_valid_until'] as $date) {
            $data[$date] = self::nullIfBlank($data[$date]);
        }
        $data['ip_mode'] = $data['ip_mode'] ?: 'pool';
        $data['auth_method'] = self::normalizeAuthMethod($data['auth_method'] ?? 'pppoe');
        $data['simultaneous_use'] = null;
        $data['custom_radius_attributes'] = '';
        $data['balance'] = self::decimalOrZero($data['balance']);

        if ($oldUsername && Db::value('SELECT COUNT(*) FROM customer_profiles WHERE username = ?', [$oldUsername])) {
            // One source of truth: billing owns balance/payment state (refreshCustomerBalance() derives it
            // from invoices) and billing_mode (set from the service); quota columns are unused. A client
            // save must never write them back from a form that may be out of date.
            $owned = ['balance', 'invoice_alert', 'payment_status', 'billing_mode', 'download_limit', 'upload_limit', 'total_traffic_limit', 'available_online_time'];
            $editable = array_diff_key($data, array_flip($owned));
            $sets = implode(', ', array_map(fn ($f) => "{$f} = ?", array_keys($editable)));
            Db::exec("UPDATE customer_profiles SET {$sets}, updated_at = NOW() WHERE username = ?", array_merge(array_values($editable), [$oldUsername]));
        } else {
            $data['balance'] = '0.00';
            $data['invoice_alert'] = 0;
            $data['payment_status'] = 'current';
            $data['billing_mode'] = $data['billing_mode'] ?: 'prepaid';
            $data['download_limit'] = $data['upload_limit'] = $data['total_traffic_limit'] = $data['available_online_time'] = null; // Quotas are not offered.
            Db::exec(
                'INSERT INTO customer_profiles (' . implode(',', $fields) . ', registration_date, created_at, updated_at) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ', NOW(), NOW(), NOW())',
                array_values($data)
            );
        }
    }

    private static function normalizeAccountStatus(array &$input): void
    {
        $status = (string) ($input['account_status'] ?? 'enabled');
        if ($status === 'enabled') {
            $input['enabled'] = '1';
            $input['account_status'] = 'active';
            return;
        }

        if (in_array($status, ['disabled', 'suspended'], true)) {
            $input['enabled'] = '0';
            return;
        }

        $input['account_status'] = 'active';
        $input['enabled'] = !empty($input['enabled']) ? '1' : '0';
    }

    private static function replaceUserChecks(string $username, array $input): void
    {
        $authMethod = self::normalizeAuthMethod($input['auth_method'] ?? 'pppoe');
        $password = '';
        if ($authMethod === 'pppoe') {
            $password = (string) ($input['password'] ?? '');
        }
        if ($authMethod === 'pppoe' && $password === '') {
            $password = self::existingPasswordFor($username);
        }

        self::deleteAttrs('radcheck', 'username', $username, self::MANAGED_CHECK);
        if ($password !== '') {
            self::insertAttr('radcheck', 'username', $username, 'Cleartext-Password', ':=', $password);
        }
        if (empty($input['enabled']) || in_array(($input['account_status'] ?? 'active'), ['disabled', 'suspended'], true)) {
            self::insertAttr('radcheck', 'username', $username, 'Auth-Type', ':=', 'Reject');
        } elseif ($authMethod === 'dhcp') {
            // Only the trusted DHCP virtual server may override this fail-closed default.
            self::insertAttr('radcheck', 'username', $username, 'Auth-Type', ':=', 'Reject');
        }
        if ($authMethod==='pppoe') {
            self::insertAttr('radcheck','username',$username,'Framed-Protocol','==','PPP');
            self::insertAttr('radcheck','username',$username,'Service-Type','==','Framed-User');
        }
        if ($authMethod==='pppoe' && !empty($input['mac_lock']) && !empty($input['mac_address'])) {
            self::insertAttr('radcheck', 'username', $username, 'Calling-Station-Id', '==', (string) $input['mac_address']);
        }
        if (($input['nas_restriction_type'] ?? 'all') === 'nas_ip' && !empty($input['nas_restriction_value'])) {
            self::insertAttr('radcheck', 'username', $username, 'NAS-IP-Address', '==', (string) $input['nas_restriction_value']);
        }
        if (($input['nas_restriction_type'] ?? 'all') === 'nas_identifier' && !empty($input['nas_restriction_value'])) {
            self::insertAttr('radcheck', 'username', $username, 'NAS-Identifier', '==', (string) $input['nas_restriction_value']);
        }
        $expiredServiceConfigured = self::expiredServiceGroupFor((string) ($input['service_group'] ?? '')) !== '';
        if ((!empty($input['expiry_date']) || ($input['account_status'] ?? '') === 'expired') && (!$expiredServiceConfigured || !self::isExpiredAccount($input))) {
            $expiryDate = !empty($input['expiry_date']) ? $input['expiry_date'] : date('Y-m-d', strtotime('-1 day'));
            self::insertAttr('radcheck', 'username', $username, 'Expiration', ':=', (string) Entitlement::expiryInstant((string) $expiryDate));
        }

    }

    private static function replaceUserReplies(string $username, array $input): void
    {
        self::deleteAttrs('radreply', 'username', $username, self::MANAGED_REPLY);
        if (($input['ip_mode'] ?? 'pool') === 'static' && !empty($input['framed_ip_address'])) {
            self::insertAttr('radreply', 'username', $username, 'Framed-IP-Address', ':=', (string) $input['framed_ip_address']);
        } elseif (($input['ip_mode'] ?? 'pool') === 'pool' && !empty($input['framed_pool'])) {
            self::insertAttr('radreply', 'username', $username, 'Framed-Pool', ':=', (string) $input['framed_pool']);
        }
    }

    private static function replaceUserGroup(string $username, string $group): void
    {
        Db::exec('DELETE FROM radusergroup WHERE username = ?', [$username]);
        if ($group !== '') {
            Db::exec('INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)', [$username, $group]);
        }
    }

    private static function radiusServiceGroup(array $input, string $selectedGroup): string
    {
        if (self::isExpiredAccount($input) && !empty($input['enabled']) && !in_array($input['account_status'], ['suspended', 'disabled'], true)) {
            $expiredGroup = self::expiredServiceGroupFor($selectedGroup);
            if ($expiredGroup !== '') {
                return $expiredGroup;
            }
        }

        return $selectedGroup;
    }

    private static function isExpiredAccount(array $input): bool
    {
        $expiryDate = trim((string) ($input['expiry_date'] ?? ''));
        return Entitlement::isExpired($input);
    }

    private static function expiredServiceGroupFor(string $selectedGroup): string
    {
        $configured = trim((string) (Db::value('SELECT expired_service_group FROM service_metadata WHERE group_name = ?', [$selectedGroup]) ?: ''));
        if ($configured !== '' && self::serviceIsEnabled($configured)) {
            return $configured;
        }
        if (self::serviceIsEnabled(self::EXPIRED_SERVICE_GROUP)) {
            return self::EXPIRED_SERVICE_GROUP;
        }

        return '';
    }

    private static function serviceIsEnabled(string $group, ?int $excludeId = null): bool
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM service_metadata WHERE group_name = ? AND enabled = 1 AND (? IS NULL OR id <> ?)',
            [$group, $excludeId, $excludeId]
        ) > 0;
    }

    private static function replaceGroupAttributes(string $group, array $input): void
    {
        Db::exec('DELETE FROM radgroupreply WHERE groupname = ?', [$group]);
        Db::exec('DELETE FROM radgroupcheck WHERE groupname = ?', [$group]);

        self::insertAttr('radgroupreply', 'groupname', $group, 'Framed-Protocol', ':=', 'PPP');
        self::insertAttr('radgroupreply', 'groupname', $group, 'Service-Type', ':=', 'Framed-User');

        $replyAttrs = [
            'Mikrotik-Rate-Limit' => $input['mikrotik_rate_limit'] ?? '',
            'Session-Timeout' => $input['session_timeout'] ?? '',
            'Idle-Timeout' => $input['idle_timeout'] ?? '',
        ];
        if (($input['ip_mode'] ?? 'pool') === 'pool') {
            $replyAttrs['Framed-Pool'] = $input['ip_pool'] ?? '';
        }
        foreach ($replyAttrs as $attribute => $value) {
            if ($value !== '' && $value !== null) {
                self::insertAttr('radgroupreply', 'groupname', $group, $attribute, ':=', (string) $value);
            }
        }

        if (!empty($input['simultaneous_use'])) {
            self::insertAttr('radgroupcheck', 'groupname', $group, 'Simultaneous-Use', ':=', (string) $input['simultaneous_use']);
        }

        foreach (self::parseCustomAttributes((string) ($input['custom_group_attrs'] ?? '')) as $attr) {
            self::insertAttr($attr['table'] === 'check' ? 'radgroupcheck' : 'radgroupreply', 'groupname', $group, $attr['attribute'], $attr['op'], $attr['value']);
        }
    }

    private static function parseCustomAttributes(string $text): array
    {
        $items = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(check|reply)\s+([A-Za-z0-9_.:-]+)\s*(:=|==|=|\+=)\s*(.+)$/i', $line, $m)) {
                $items[] = ['table' => strtolower($m[1]), 'attribute' => $m[2], 'op' => $m[3], 'value' => trim($m[4], "\"' ")];
            }
        }
        return $items;
    }

    private static function assertNoManagedConflicts(string $text, array $managed): void
    {
        foreach (self::parseCustomAttributes($text) as $attr) {
            if (in_array($attr['attribute'], $managed, true)) {
                throw new RuntimeException("Custom attribute {$attr['attribute']} conflicts with an app-managed RADIUS attribute.");
            }
        }
    }

    private static function deleteAttrs(string $table, string $ownerColumn, string $owner, array $attrs): void
    {
        $in = implode(',', array_fill(0, count($attrs), '?'));
        Db::exec("DELETE FROM {$table} WHERE {$ownerColumn} = ? AND attribute IN ({$in})", array_merge([$owner], $attrs));
    }

    private static function insertAttr(string $table, string $ownerColumn, string $owner, string $attribute, string $op, string $value): void
    {
        Db::exec("INSERT INTO {$table} ({$ownerColumn}, attribute, op, value) VALUES (?, ?, ?, ?)", [$owner, $attribute, $op, $value]);
    }

    private static function existingPasswordFor(string $username): string
    {
        return (string) (Db::value(
            'SELECT value FROM radcheck WHERE username = ? AND attribute = "Cleartext-Password" ORDER BY id DESC LIMIT 1',
            [$username]
        ) ?: '');
    }

    private static function serviceParams(array $input, array $tail = []): array
    {
        return array_merge([
            $input['group_name'], $input['name'], $input['description'], self::boolValue($input['enabled'] ?? null),
            $input['download_speed'], $input['upload_speed'], $input['mikrotik_rate_limit'],
            $input['burst_limit'], $input['burst_threshold'], $input['burst_time'], self::intOrNull($input['priority']),
            $input['ip_mode'], $input['ip_pool'], self::nullIfBlank($input['static_ip_pool'] ?? ''), self::intOrNull($input['session_timeout']), self::intOrNull($input['idle_timeout']), self::intOrNull($input['simultaneous_use']),
            $input['download_quota']??null, $input['upload_quota']??null, $input['total_quota']??null, $input['expiration_behavior'],
            $input['billing_mode'], self::nullIfBlank($input['expired_service_group'] ?? ''), self::decimalOrZero($input['price']), $input['currency'], $input['billing_period'] ?? 'monthly', $input['custom_group_attrs'],
        ], $tail);
    }

    private static function normalizeClientIp(array &$input, ?string $oldUsername): void
    {
        $ip=trim((string)($input['framed_ip_address'] ?? ''));
        if($ip===''){$input['framed_ip_address']=null;$input['ip_mode']=$input['ip_mode'] ?? 'pool';return;}
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){throw new RuntimeException('Select a valid IPv4 static IP address.');}
        $pools=Db::select('SELECT * FROM static_ip_pools ORDER BY id FOR UPDATE');
        $active=array_values(array_filter($pools,fn($pool)=>!empty($pool['enabled'])));
        $pool=StaticIpPool::poolFor($ip,$active);
        $current=$oldUsername ? Db::value('SELECT framed_ip_address FROM customer_profiles WHERE username=?',[$oldUsername]) : null;
        if(!$pool && $current!==$ip){throw new RuntimeException('Selected static IP is not in an enabled static IP pool.');}
        $exists=Db::value('SELECT username FROM customer_profiles WHERE allocated_static_ip=? AND (? IS NULL OR username<>?) LIMIT 1',[$ip,$oldUsername,$oldUsername]);
        if($exists){throw new RuntimeException("Static IP $ip is already assigned to $exists.");}
        $input['framed_ip_address']=$ip;$input['ip_mode']='static';
    }

    private static function normalizeAuthMethod(mixed $value): string
    {
        $method = strtolower(trim((string) $value));
        return $method === 'dhcp' ? 'dhcp' : 'pppoe';
    }

    private static function normalizeMacAddress(string $value): string
    {
        $raw = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', trim($value)) ?? '');
        if (strlen($raw) !== 12) {
            return trim($value);
        }
        return implode(':', str_split($raw, 2));
    }

    private static function nullIfBlank(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === '' || $value === null ? null : (int) $value;
    }

    private static function decimalOrZero(mixed $value): string
    {
        return $value === '' || $value === null ? '0.00' : (string) $value;
    }

    private static function boolValue(mixed $value): int
    {
        return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true) ? 1 : 0;
    }
}
