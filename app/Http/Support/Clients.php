<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\AccessPolicy;
use App\Support\Accounting;
use App\Support\Auth;
use App\Support\ClientQuery;
use App\Support\Db;
use App\Support\Entitlement;
use App\Support\NasRepository;
use App\Support\SchemaCompatibility;
use App\Support\SessionDisconnect;
use App\Support\StaticIpPool;

/** Client presentation shared by the client pages, Billing and diagnostics (legacy app/Http/clients.php). */
final class Clients
{
    public static function isArchived(?array $row): bool
    {
        return ($row['account_status'] ?? '') === 'archived';
    }

    public static function statusLabel(?array $row): string
    {
        if (!$row) {
            return 'draft';
        }
        return match (true) {
            self::isArchived($row) => 'archived',
            ($row['account_status'] ?? '') === 'suspended' => 'suspended',
            ($row['account_status'] ?? '') === 'disabled' => 'disabled',
            ($row['account_status'] ?? '') === 'expired' => 'expired',
            empty($row['enabled']) => 'disabled',
            Entitlement::isExpired($row) => 'expired',
            default => 'enabled',
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'enabled' => 'success',
            'expired' => 'warning',
            'disabled', 'suspended', 'archived' => 'danger',
            default => '',
        };
    }

    public static function statusTag(?array $row): array
    {
        $status = self::statusLabel($row);
        return Present::tag($status, self::statusTone($status));
    }

    /** The form's three-way status select. */
    public static function statusFormValue(?array $row): string
    {
        if (!$row) {
            return 'enabled';
        }
        if (($row['account_status'] ?? '') === 'suspended') {
            return 'suspended';
        }
        return empty($row['enabled']) || ($row['account_status'] ?? '') === 'disabled' ? 'disabled' : 'enabled';
    }

    public static function displayName(array $client): string
    {
        $name = trim((string) (($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')));
        return $name !== '' ? $name : (string) (($client['company_name'] ?? '') ?: ($client['username'] ?? ''));
    }

    public static function authTag(array $client): array
    {
        return ($client['auth_method'] ?? 'pppoe') === 'dhcp' ? Present::tag('DHCP MAC', 'success') : Present::tag('PPPoE');
    }

    public static function accountingLabel(array $row): string
    {
        return Format::connectionLabel($row['online_count'] ? 'fresh accounting' : ($row['stale_count'] ? 'stale accounting' : 'no fresh accounting'));
    }

    /** Summary band above the client form. */
    public static function header(array $client, array $planNames, int $openInvoices): array
    {
        $name = self::displayName($client);
        $words = preg_split('/[\s._-]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [(string) $client['username']];
        $initials = strtoupper(mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : mb_substr($words[0], 1, 1)));
        $expiry = trim((string) ($client['expiry_date'] ?? ''));
        $expiryNote = 'No expiry set';
        $expiryTone = '';
        if ($expiry !== '' && ($time = strtotime($expiry)) !== false) {
            $days = (int) floor(($time - strtotime('today')) / 86400);
            $expiryNote = $days < 0 ? 'Expired ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago' : ($days === 0 ? 'Expires today' : 'In ' . $days . ' day' . ($days === 1 ? '' : 's'));
            $expiryTone = $days < 0 ? 'danger' : ($days <= 7 ? 'warning' : '');
        }
        $payment = (string) (($client['payment_status'] ?? '') ?: 'current');
        $paymentTone = Invoices::paymentTone($payment);
        $contacts = [];
        foreach ([['phone', ($client['cell_phone'] ?? '') ?: ($client['phone'] ?? '')], ['mail', $client['email'] ?? ''], ['server', trim(implode(', ', array_filter([$client['city'] ?? '', $client['country'] ?? ''])))]] as [$icon, $value]) {
            if (trim((string) $value) !== '') {
                $contacts[] = ['icon' => $icon, 'text' => (string) $value];
            }
        }
        $stat = static fn (string $label, string $value, string $note = '', string $tone = '') => compact('label', 'value', 'note', 'tone');
        return [
            'name' => $name,
            'initials' => $initials,
            'status' => self::statusTag($client),
            'auth' => self::authTag($client),
            'contacts' => $contacts,
            'newInvoice' => Auth::can('billing.manage') && !self::isArchived($client),
            'stats' => [
                $stat('Service plan', (string) ($planNames[$client['service_group'] ?? ''] ?? ($client['service_group'] ?? '') ?: '-')),
                $stat('Expiry date', $expiry !== '' ? $expiry : '-', $expiryNote, $expiryTone),
                $stat('Balance', Format::money((float) ($client['balance'] ?? 0)), 'Payment ' . $payment, $paymentTone === 'success' ? '' : ($paymentTone ?: 'warning')),
                $stat('Open invoices', (string) $openInvoices, $openInvoices ? 'Awaiting payment' : 'All settled', $openInvoices ? 'warning' : ''),
            ],
        ];
    }

    /** Account overview side panel (also the JSON of its live refresh). */
    public static function overview(array $row): array
    {
        $username = (string) $row['username'];
        $ids = ClientQuery::usernames($username);
        $online = Db::row(
            'SELECT r.*, n.id AS nas_id, n.shortname AS nas_shortname, n.nasname AS nas_name, n.type AS nas_type, n.secret AS nas_secret,
                    m.vendor AS nas_vendor, COALESCE(m.enabled, 1) AS nas_enabled,
                    COALESCE(m.coa_enabled, 1) AS coa_enabled, COALESCE(m.coa_port, 3799) AS coa_port,
                    m.config_revision, m.applied_revision, m.pending_delete, m.api_host, m.api_user, m.api_password, ' . NasRepository::apiEnabledSql('m') . ' AS api_enabled
             FROM radacct r
             LEFT JOIN nas n ON n.nasname = r.nasipaddress OR n.shortname = r.nasipaddress
             LEFT JOIN nas_metadata m ON m.nas_id = n.id
             WHERE ' . ClientQuery::inUsernames('r.username', $ids) . ' AND ' . Accounting::freshSql('r') . '
             ORDER BY r.acctstarttime DESC LIMIT 1',
            $ids
        );
        $last = Db::row('SELECT * FROM radacct WHERE ' . ClientQuery::inUsernames('username', $ids) . ' ORDER BY acctstarttime DESC LIMIT 1', $ids);
        $traffic = Db::row('SELECT SUM(acctoutputoctets) down, SUM(acctinputoctets) up FROM radacct WHERE ' . ClientQuery::inUsernames('username', $ids), $ids) ?? [];
        $nasLabel = $online ? (trim((string) ($online['nas_shortname'] ?: $online['nas_name'] ?: '')) ?: '-') : '-';
        $applied = $online && !empty($online['nas_enabled']) && empty($online['pending_delete']) && $online['applied_revision'] !== null && (int) $online['applied_revision'] === (int) $online['config_revision'];
        $profile = !SchemaCompatibility::problems('policy') ? Db::row('SELECT p.* FROM nas_access_profiles p JOIN subscriptions s ON s.access_profile_id=p.id WHERE s.customer_id=?', [$row['id']]) : null;
        // With an access profile the certified network worker disconnects; without one the portal sends
        // the disconnect itself (RADIUS CoA, or the RouterOS API when it is switched on for the NAS).
        $capability = $profile ? AccessPolicy::capability($profile, 'disconnect') : null;
        $method = $online && !$profile ? SessionDisconnect::disconnectMethod($online) : null;
        $authMethod = (string) ($row['auth_method'] ?? 'pppoe');
        $state = $online ? Accounting::state($online) : ($last ? Accounting::state($last) : 'unknown');
        $status = self::statusLabel($row);

        $items = [
            ['Username', $username],
            ['Authentication', $authMethod === 'dhcp' ? 'DHCP MAC' : 'PPPoE'],
            ['Connection status', $state, $state === 'fresh accounting' ? 'online' : 'warning'],
            ['Live source', $online ? 'Recent RADIUS accounting (not a reachability probe)' : '-'],
            ['RADIUS accounting', $online ? 'yes' : '-'],
            ['Current IP', (string) ($online['framedipaddress'] ?? ($row['framed_ip_address'] ?? '-'))],
            ['Connected NAS', $nasLabel],
            ['Session ID', (string) ($online['acctsessionid'] ?? '-')],
            ['Caller ID', (string) ($online['callingstationid'] ?? ($row['mac_address'] ?? '-'))],
            ['Session started', (string) ($online['acctstarttime'] ?? '-')],
            ['Last session update', (string) ($online['acctupdatetime'] ?? $online['acctstarttime'] ?? '-')],
            [($authMethod === 'dhcp' ? 'DHCP lease' : 'PPPoE session') . ' time', $online ? Format::pppoeSessionTime($online) : '-'],
            ['Session download', $online ? Format::bytes(Format::acctDownload($online)) : '-'],
            ['Session upload', $online ? Format::bytes(Format::acctUpload($online)) : '-'],
            ['Account status', $status, self::statusTone($status)],
            ['Billing state', (string) ($row['payment_status'] ?? 'unknown')],
            ['Service entitlement', $status],
            ['Service plan', (string) ($row['service_group'] ?? '-')],
            ['Cumulative session download', Format::bytes((int) ($traffic['down'] ?? 0))],
            ['Cumulative session upload', Format::bytes((int) ($traffic['up'] ?? 0))],
            ['Cumulative session total', Format::bytes((int) ($traffic['down'] ?? 0) + (int) ($traffic['up'] ?? 0))],
        ];
        if (!$online && !empty($last['acctstoptime'])) {
            $items[] = ['Last disconnect', (string) $last['acctstoptime']];
        }
        $disconnect = null;
        if ($online && Auth::can('clients.disconnect')) {
            $reason = !$applied ? 'NAS configuration is pending deployment.' : ($capability ?? ($method && !$method['ok'] ? 'No disconnect method on NAS ' . $nasLabel . ': turn on CoA on its Device tab, or the RouterOS API.' : null));
            $via = $method ? ($method['method'] === 'api' ? ' through the RouterOS API' : ' as a RADIUS disconnect (CoA)') : '';
            $disconnect = $reason === null
                ? ['ready' => true, 'nasId' => (int) $online['nas_id'], 'radacctId' => (int) $online['radacctid'],
                    'message' => 'Sends a disconnect' . $via . ' for session ' . ($online['acctsessionid'] ?? '') . ' on NAS ' . $nasLabel . ' (' . ($online['framedipaddress'] ?? '') . '). The session drops now; most CPEs redial on their own.']
                : ['ready' => false, 'reason' => $reason];
        }
        return [
            'items' => array_map(static fn ($i) => ['label' => $i[0], 'value' => $i[1], 'tone' => $i[2] ?? ''], $items),
            'revealPassword' => $authMethod === 'pppoe' && Auth::can('clients.manage') && !self::isArchived($row),
            'disconnect' => $disconnect,
        ];
    }

    /** Static IP choices: pools with their free addresses (plus the client's current one). */
    public static function staticIpOptions(?array $client): array
    {
        $currentUsername = (string) ($client['username'] ?? '');
        $currentIp = (string) ($client['framed_ip_address'] ?? '');
        $taken = [];
        foreach (Db::select('SELECT username, framed_ip_address FROM customer_profiles WHERE framed_ip_address IS NOT NULL AND framed_ip_address <> ""') as $row) {
            if ($row['username'] !== $currentUsername) {
                $taken[(string) $row['framed_ip_address']] = true;
            }
        }
        $pools = [];
        $currentPool = '';
        foreach (Db::select('SELECT pool_key, name, ip_ranges FROM static_ip_pools WHERE enabled=1 ORDER BY name') as $pool) {
            $ips = StaticIpPool::parse((string) ($pool['ip_ranges'] ?? ''));
            if (!$ips) {
                continue;
            }
            if ($currentIp !== '' && in_array($currentIp, $ips, true)) {
                $currentPool = (string) $pool['pool_key'];
            }
            $pools[] = [
                'key' => (string) $pool['pool_key'],
                'label' => ($pool['name'] ?: $pool['pool_key']) . ' (' . count($ips) . ' IPs)',
                'ips' => array_values(array_filter($ips, static fn ($ip) => !isset($taken[$ip]) || $ip === $currentIp)),
            ];
        }
        if ($currentIp !== '' && $currentPool === '') {
            $currentPool = '__current';
            array_unshift($pools, ['key' => '__current', 'label' => 'Current assigned IP', 'ips' => [$currentIp]]);
        }
        return ['pools' => $pools, 'currentPool' => $currentPool, 'currentIp' => $currentIp];
    }

    /** NAS/access profile binding fields; null before migration 018. */
    public static function binding(?array $client): ?array
    {
        if (SchemaCompatibility::problems('policy')) {
            return null;
        }
        $sub = $client ? Db::row('SELECT * FROM subscriptions WHERE customer_id=?', [$client['id']]) : null;
        $options = ['' => 'Unbound — acceptance required'];
        foreach (Db::select('SELECT id,adapter,source_ip,software_version FROM nas_access_profiles ORDER BY id') as $p) {
            $options[(string) $p['id']] = $p['adapter'] . ' · ' . $p['source_ip'] . ' · ' . ($p['software_version'] ?: 'release unknown');
        }
        return [
            'options' => $options,
            'access_profile_id' => (string) ($sub['access_profile_id'] ?? ''),
            'dhcp_client_id' => (string) ($sub['dhcp_client_id'] ?? ''),
            'circuit_id' => (string) ($sub['circuit_id'] ?? ''),
        ];
    }
}
