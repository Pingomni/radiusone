<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Policy\Adapter;
use RuntimeException;

final class RadiusRepository
{
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
        if ($input['username'] === '' || strlen($input['username']) > 64 || preg_match('/[\x00-\x20\x7f]/', $input['username'])) {
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
            if (!$profile) {
                // A new client: within the licence's client limit (RadiusOne Community 150, Pro 999).
                License::assertRoomForClient();
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
            if ($serviceGroup === '' || !Db::row('SELECT group_name FROM service_metadata WHERE group_name=? AND enabled=1 FOR UPDATE', [$serviceGroup])) {
                throw new RuntimeException('Select an enabled service plan for this client.');
            }
            $input['custom_radius_attributes'] = '';
            self::normalizeClientIp($input, $oldUsername);

            self::upsertProfile($input, $oldUsername);
            SubscriberPolicy::bind(Db::row('SELECT * FROM customer_profiles WHERE username=?', [$username]), $input);
            BillingRepository::refreshCustomerBalance($username);
            self::provisionClient($username, (string) ($input['password'] ?? ''));

            $saved = Db::row('SELECT id,expiry_date FROM customer_profiles WHERE username=?', [$username]);
            if (!$profile) {
                CustomerNotifications::enrolled((int) $saved['id']);
            } else {
                CustomerNotifications::extended((int) $saved['id'], $profile['expiry_date'], $saved['expiry_date']);
            }
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
            return $username;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                    SubscriberPolicy::releaseLocks();
                } catch (\Throwable) { /* Preserve the original failure. */
                }
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
            if (!$client) {
                throw new RuntimeException('Client not found.');
            }
            if ($client['account_status'] !== 'archived') {
                foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
                    Db::exec("DELETE FROM {$table} WHERE username=?", [$username]);
                }
                // Keep an explicit rejection so fallback/unmanaged authentication cannot revive this identity.
                Db::exec('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, "Auth-Type", ":=", "Reject")', [$username]);
                Db::exec('UPDATE customer_profiles SET ' . $archiveDate . 'enabled=0, account_status="archived", updated_at=NOW() WHERE username=?', [$username]);
                $archived = Db::row('SELECT * FROM customer_profiles WHERE username=?', [$username]);
                SubscriberPolicy::publish($archived, (string) $archived['service_group']);
            }
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                    SubscriberPolicy::releaseLocks();
                } catch (\Throwable) { /* Preserve the original failure. */
                }
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
            $current = $id ? Db::value('SELECT group_name FROM service_metadata WHERE id=?', [$id]) : null;
            if ($current !== $group) {
                foreach (['service_metadata' => 'group_name', 'radgroupcheck' => 'groupname', 'radgroupreply' => 'groupname'] as $table => $column) {
                    if (Db::row("SELECT $column FROM $table WHERE $column=? LIMIT 1 FOR UPDATE", [$group])) {
                        throw new RuntimeException('Service name collides with managed or unmanaged RADIUS group.');
                    }
                }
            }
            $expiredGroup = trim((string) ($input['expired_service_group'] ?? ''));
            if ($expiredGroup !== '' && !self::serviceIsEnabled($expiredGroup, $id)) {
                throw new RuntimeException('Select an enabled expired service, or leave it blank.');
            }
            $quota = trim((string) ($input['total_quota'] ?? ''));
            if ($quota !== '' && (!is_numeric($quota) || (float) $quota <= 0 || (float) $quota > 1000000)) {
                throw new RuntimeException('Enter the data cap in GB (a number above 0), or leave it blank for no cap.');
            }
            $input['total_quota'] = $quota === '' ? null : $quota;
            $input['cap_action'] = ($input['cap_action'] ?? 'notify') === 'throttle' && $quota !== '' ? 'throttle' : 'notify';
            $capGroup = $input['cap_action'] === 'throttle' ? trim((string) ($input['cap_service_group'] ?? '')) : '';
            if ($input['cap_action'] === 'throttle' && ($capGroup === '' || $capGroup === $group || !self::serviceIsEnabled($capGroup, $id))) {
                throw new RuntimeException('Choose the enabled plan to slow customers down to when they reach the cap.');
            }
            $input['cap_service_group'] = $capGroup;
            $download = Adapter::bps($input['download_speed'] ?? null);
            $upload = Adapter::bps($input['upload_speed'] ?? null);
            if (($download === null) !== ($upload === null)) {
                throw new RuntimeException('Supply both download and upload speeds.');
            }
            $input['mikrotik_rate_limit'] = $download === null ? '' : $upload . '/' . $download;
            self::assertNoManagedConflicts((string) ($input['custom_group_attrs'] ?? ''), array_merge(self::MANAGED_CHECK, self::MANAGED_REPLY));

            if ($id) {
                $before = Db::row('SELECT * FROM service_metadata WHERE id = ? FOR UPDATE', [$id]);
                if (!$before) {
                    throw new RuntimeException('Service not found.');
                }
                $oldGroup = $before['group_name'];
                if (empty($input['enabled'])) {
                    self::assertServiceUnreferenced($oldGroup);
                }
                Db::exec(
                    'UPDATE service_metadata SET group_name=?, name=?, description=?, enabled=?, download_speed=?, upload_speed=?, mikrotik_rate_limit=?, burst_limit=?, burst_threshold=?, burst_time=?, priority=?, ip_mode=?, ip_pool=?, static_ip_pool=?, session_timeout=?, idle_timeout=?, simultaneous_use=?, download_quota=?, upload_quota=?, total_quota=?, cap_action=?, cap_service_group=?, expiration_behavior=?, billing_mode=?, expired_service_group=?, price=?, currency=?, billing_period=?, custom_group_attrs=?, updated_at=NOW() WHERE id=?',
                    self::serviceParams($input, [$id])
                );
                if ($oldGroup !== $group) {
                    foreach (['customer_profiles', 'billing_invoices', 'billing_invoice_items'] as $table) {
                        Db::exec("UPDATE $table SET service_group=? WHERE service_group=?", [$group, $oldGroup]);
                    }
                    Db::exec('UPDATE radusergroup SET groupname = ? WHERE groupname = ?', [$group, $oldGroup]);
                    Db::exec('UPDATE service_metadata SET expired_service_group = ? WHERE expired_service_group = ?', [$group, $oldGroup]);
                    Db::exec('UPDATE service_metadata SET cap_service_group = ? WHERE cap_service_group = ?', [$group, $oldGroup]);
                    Db::exec('DELETE FROM radgroupcheck WHERE groupname = ?', [$oldGroup]);
                    Db::exec('DELETE FROM radgroupreply WHERE groupname = ?', [$oldGroup]);
                }
            } else {
                Db::exec(
                    'INSERT INTO service_metadata (group_name, name, description, type, enabled, download_speed, upload_speed, mikrotik_rate_limit, burst_limit, burst_threshold, burst_time, priority, ip_mode, ip_pool, static_ip_pool, session_timeout, idle_timeout, simultaneous_use, download_quota, upload_quota, total_quota, cap_action, cap_service_group, expiration_behavior, billing_mode, expired_service_group, price, currency, billing_period, custom_group_attrs, created_at, updated_at)
                     VALUES (?, ?, ?, "PPPoE", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    self::serviceParams($input)
                );
                $id = (int) $pdo->lastInsertId();
            }

            self::replaceGroupAttributes($group, $input);
            Db::exec('UPDATE service_metadata SET policy_revision=policy_revision+1 WHERE id=?', [$id]);
            Db::exec('UPDATE subscriptions u JOIN customer_profiles c ON c.id=u.customer_id SET u.dirty=1 WHERE c.service_group=? OR c.service_group IN (SELECT group_name FROM service_metadata WHERE expired_service_group=?) OR ?="expired"', [$group, $group, $group]);
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
            return (int) $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                    SubscriberPolicy::releaseLocks();
                } catch (\Throwable) { /* Preserve the original failure. */
                }
            }
            throw $e;
        }
    }

    /** Caller owns the transaction; all transitions lock the profile before changing provisioning. */
    public static function provisionClient(string $username, string $password = ''): void
    {
        if (!Db::pdo()->inTransaction()) {
            throw new RuntimeException('Provisioning requires a transaction.');
        }
        $client = Db::row('SELECT * FROM customer_profiles WHERE username=? FOR UPDATE', [$username]);
        if (!$client || $client['account_status'] === 'archived') {
            return;
        }
        $client['password'] = $password;
        $expired = self::isExpiredAccount($client);
        if (!in_array($client['account_status'], ['suspended', 'disabled'], true)) {
            $client['account_status'] = $expired ? 'expired' : 'active';
            Db::exec('UPDATE customer_profiles SET account_status=? WHERE username=?', [$client['account_status'], $username]);
        }
        self::replaceUserChecks($username, $client);
        self::replaceUserReplies($username, $client);
        $effectiveGroup = self::radiusServiceGroup($client, (string) $client['service_group']);
        self::replaceUserGroup($username, $effectiveGroup);
        SubscriberPolicy::publish($client, $effectiveGroup);
    }

    public static function setClientStatus(string $username, string $status): void
    {
        SchemaCompatibility::require('policy');
        if (!in_array($status, ['disabled', 'suspended'], true)) {
            throw new RuntimeException('Invalid status.');
        }
        SchemaCompatibility::require('policy');
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $row = Db::row('SELECT account_status FROM customer_profiles WHERE username=? FOR UPDATE', [$username]);
            if (!$row || $row['account_status'] === 'archived') {
                throw new RuntimeException('Client not found or archived.');
            }
            Db::exec('UPDATE customer_profiles SET enabled=0, account_status=?, updated_at=NOW() WHERE username=?', [$status, $username]);
            self::provisionClient($username);
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                SubscriberPolicy::releaseLocks();
            } throw $e;
        }
    }

    public static function syncExpiredServiceGroups(): int
    {
        return SubscriberPolicy::due();
    }

    public static function deleteService(int $id): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $service = Db::row('SELECT group_name FROM service_metadata WHERE id=? FOR UPDATE', [$id]);
            if ($service) {
                self::assertServiceUnreferenced((string) $service['group_name']);
                foreach (['radgroupcheck', 'radgroupreply'] as $table) {
                    Db::exec("DELETE FROM $table WHERE groupname=?", [$service['group_name']]);
                }
                Db::exec('DELETE FROM service_metadata WHERE id=?', [$id]);
            }
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                SubscriberPolicy::releaseLocks();
            } throw $e;
        }
    }

    private static function assertServiceUnreferenced(string $group): void
    {
        foreach (['customer_profiles' => 'service_group', 'billing_invoices' => 'service_group', 'billing_invoice_items' => 'service_group', 'service_metadata' => 'expired_service_group', 'radusergroup' => 'groupname'] as $table => $column) {
            if (Db::value("SELECT COUNT(*) FROM $table WHERE $column=?", [$group])) {
                throw new RuntimeException('Referenced services cannot be deleted or disabled.');
            }
        }
        if (Db::value('SELECT COUNT(*) FROM service_metadata WHERE cap_service_group=? AND cap_action="throttle"', [$group])) {
            throw new RuntimeException('This plan is the slow-down plan of a data cap; choose another one there first.');
        }
    }

    private static function assertIdentityAvailable(string $username, ?string $old): void
    {
        if ($username === $old) {
            return;
        }
        if (Db::row('SELECT username FROM customer_identity_history WHERE username=? FOR UPDATE', [$username])) {
            throw new RuntimeException('Username is reserved by historical accounting identity.');
        }
        foreach (['customer_profiles', 'radcheck', 'radreply', 'radusergroup', 'radacct', 'radpostauth'] as $table) {
            if (Db::row("SELECT username FROM $table WHERE username=? LIMIT 1 FOR UPDATE", [$username])) {
                throw new RuntimeException('Username already exists in managed or unmanaged RADIUS/history records.');
            }
        }
        if ($old) {
            $profile = Db::row('SELECT id FROM customer_profiles WHERE username=? FOR UPDATE', [$old]);
            if (!$profile) {
                throw new RuntimeException('Client not found.');
            }
            foreach (['billing_invoices', 'billing_payments', 'uploaded_documents', 'notification_logs'] as $table) {
                if (Db::value("SELECT COUNT(*) FROM $table WHERE username=?", [$old]) && !Db::value('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME="customer_profiles" AND UPDATE_RULE="CASCADE"', [$table])) {
                    throw new RuntimeException('Identity reference migration is required before renaming this customer.');
                }
            }
        }
    }

    private static function renameRadiusUser(string $old, string $new): void
    {
        $id = Db::value('SELECT id FROM customer_profiles WHERE username=?', [$old]);
        Db::exec('INSERT INTO customer_identity_history (username,customer_id,renamed_at) VALUES (?,?,NOW())', [$old, $id]);
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            Db::exec("UPDATE $table SET username=? WHERE username=?", [$new, $old]);
        }
        // Historical events are immutable. Reserve and reject the former network identity.
        Db::exec('INSERT INTO radcheck (username,attribute,op,value) VALUES (?,"Auth-Type",":=","Reject")', [$old]);
    }

    private static function upsertProfile(array $input, ?string $oldUsername): void
    {
        $fields = [
            'username', 'auth_method', 'enabled', 'verified', 'email_alert', 'sms_alert', 'account_status', 'expiry_date', 'available_online_time', 'simultaneous_use',
            'service_group', 'owner_operator_id', 'ip_mode', 'framed_ip_address', 'framed_pool', 'mac_address', 'mac_lock', 'nas_restriction_type', 'nas_restriction_value',
            'first_name', 'last_name', 'company_name', 'address', 'city', 'state', 'zip', 'country', 'phone', 'cell_phone', 'email', 'vat_tax_id', 'contract_id', 'contract_valid_until', 'gps', 'language', 'notes',
            'download_limit', 'upload_limit', 'total_traffic_limit', 'balance', 'invoice_alert', 'payment_status', 'billing_mode', 'custom_radius_attributes',
        ];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $input[$field] ?? null;
        }
        foreach (['enabled', 'verified', 'email_alert', 'sms_alert', 'mac_lock', 'invoice_alert'] as $bool) {
            $data[$bool] = self::boolValue($input[$bool] ?? null);
        }
        // No restriction unless one is given, as the RADIUS policy below already assumes.
        $data['nas_restriction_type'] = ($input['nas_restriction_type'] ?? '') ?: 'all';
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
        if ($authMethod === 'pppoe') {
            self::insertAttr('radcheck', 'username', $username, 'Framed-Protocol', '==', 'PPP');
            self::insertAttr('radcheck', 'username', $username, 'Service-Type', '==', 'Framed-User');
        }
        if ($authMethod === 'pppoe' && !empty($input['mac_lock']) && !empty($input['mac_address'])) {
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
        // Over the plan's data cap on a "slow down" plan: the slow plan until the next cycle (UsageCaps).
        $capped = self::isExpiredAccount($input) ? null : UsageCaps::cappedGroup($input, $selectedGroup);
        if ($capped !== null) {
            return $capped;
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
            $input['download_quota'] ?? null, $input['upload_quota'] ?? null, $input['total_quota'] ?? null, $input['cap_action'] ?? 'notify', self::nullIfBlank($input['cap_service_group'] ?? ''), $input['expiration_behavior'],
            $input['billing_mode'], self::nullIfBlank($input['expired_service_group'] ?? ''), self::decimalOrZero($input['price']), $input['currency'], $input['billing_period'] ?? 'monthly', $input['custom_group_attrs'],
        ], $tail);
    }

    private static function normalizeClientIp(array &$input, ?string $oldUsername): void
    {
        $ip = trim((string) ($input['framed_ip_address'] ?? ''));
        if ($ip === '') {
            $input['framed_ip_address'] = null;
            $input['ip_mode'] = $input['ip_mode'] ?? 'pool';
            return;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('Select a valid IPv4 static IP address.');
        }
        $pools = Db::select('SELECT * FROM static_ip_pools ORDER BY id FOR UPDATE');
        $active = array_values(array_filter($pools, fn ($pool) => !empty($pool['enabled'])));
        $pool = StaticIpPool::poolFor($ip, $active);
        $current = $oldUsername ? Db::value('SELECT framed_ip_address FROM customer_profiles WHERE username=?', [$oldUsername]) : null;
        if (!$pool && $current !== $ip) {
            throw new RuntimeException('Selected static IP is not in an enabled static IP pool.');
        }
        $exists = Db::value('SELECT username FROM customer_profiles WHERE allocated_static_ip=? AND (? IS NULL OR username<>?) LIMIT 1', [$ip, $oldUsername, $oldUsername]);
        if ($exists) {
            throw new RuntimeException("Static IP $ip is already assigned to $exists.");
        }
        $input['framed_ip_address'] = $ip;
        $input['ip_mode'] = 'static';
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

    public static function boolValue(mixed $value): int
    {
        return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true) ? 1 : 0;
    }
}
