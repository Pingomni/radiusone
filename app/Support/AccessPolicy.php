<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Policy\Adapter;
use App\Support\Policy\IosXeIpoe;
use App\Support\Policy\IosXePppoe;
use App\Support\Policy\RouterOsDhcp;
use App\Support\Policy\RouterOsPppoe;
use RuntimeException;

final class AccessPolicy
{
    public static function adapter(string $key): Adapter
    {
        return match ($key) {
            'routeros_pppoe' => new RouterOsPppoe,'routeros_dhcp' => new RouterOsDhcp,'iosxe_pppoe' => new IosXePppoe,'iosxe_ipoe' => new IosXeIpoe,default => throw new RuntimeException('Select a supported NAS/access profile.')
        };
    }
    public static function mac(string $raw): string
    {
        if (!preg_match('/^(?:[0-9a-f]{12}|[0-9a-f]{2}(?:[:-][0-9a-f]{2}){5}|[0-9a-f]{4}(?:\.[0-9a-f]{4}){2})$/i', $raw)) {
            throw new RuntimeException('Invalid MAC address.');
        }
        return implode(':', str_split(strtoupper(str_replace([':', '-', '.'], '', $raw)), 2));
    }
    public static function clientId(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (strlen($raw) > 253 || preg_match('/[\x00-\x1f\x7f]/', $raw)) {
            throw new RuntimeException('Invalid DHCP client identifier.');
        }
        // Opaque, byte-exact identifier. 01:MAC, DUID and text are not interchangeable.
        return $raw;
    }
    /** Context comes from the authenticated RADIUS client + dedicated listener, never packet circuit claims alone. */
    public static function decision(array $policy, array $request, array $trusted, ?int $now = null): array
    {
        $deny = static fn (string $reason) => ['allow' => false, 'reason' => $reason];
        if (empty($trusted['authenticated_client']) || ($trusted['source_ip'] ?? null) !== ($policy['source_ip'] ?? null)
            || ($trusted['listener_key'] ?? null) !== ($policy['listener_key'] ?? null)) {
            return $deny('untrusted_nas_or_listener');
        }
        if (empty($policy['allowed']) || (!empty($policy['valid_until']) && ($now ?? time()) >= (int) $policy['valid_until'])) {
            return $deny('entitlement_or_capability_blocked');
        }
        $adapter = self::adapter($policy['adapter']);
        if ($policy['adapter'] !== 'routeros_dhcp' && ($request['User-Name'] ?? '') !== ($policy['username'] ?? '')) {
            return $deny('identity_mismatch');
        }
        $ppp = isset($request['Framed-Protocol']) || isset($request['CHAP-Password']) || isset($request['MS-CHAP-Challenge']) || isset($request['MS-CHAP-Response']) || isset($request['MS-CHAP2-Response']) || isset($request['EAP-Message']);
        if ($adapter->isPpp()) {
            if (($request['Framed-Protocol'] ?? '') !== 'PPP' || ($request['Service-Type'] ?? '') !== 'Framed-User') {
                return $deny('ppp_required');
            }
        } elseif ($ppp || ($request['Service-Type'] ?? '') === 'Framed-User') {
            return $deny('cross_protocol_rejected');
        }
        if (!empty($policy['circuit_id']) && (empty($trusted['circuit_from_nas']) || ($request['NAS-Port-Id'] ?? '') !== $policy['circuit_id'])) {
            return $deny('circuit_mismatch');
        }
        if (!$adapter->isPpp()) {
            if (($request['Called-Station-Id'] ?? '') !== ($policy['dhcp_server'] ?? '')) {
                return $deny('dhcp_server_mismatch');
            }
            if ($policy['dhcp_client_id'] !== null && ($request['Calling-Station-Id'] ?? '') !== $policy['dhcp_client_id']) {
                return $deny('client_identifier_mismatch');
            }
            // RouterOS local DHCP uses MAC User-Name. IOS XE layout requires capture acceptance.
            if ($policy['adapter'] === 'routeros_dhcp') {
                try {
                    $mac = self::mac((string) ($request['User-Name'] ?? ''));
                } catch (RuntimeException) {
                    return $deny('invalid_mac');
                }
                if ($mac !== $policy['mac_address']) {
                    return $deny('mac_mismatch');
                }
            }
        } elseif (!empty($policy['mac_address'])) {
            try {
                $mac = self::mac((string) ($request['Calling-Station-Id'] ?? ''));
            } catch (RuntimeException) {
                return $deny('invalid_mac');
            }
            if ($mac !== $policy['mac_address']) {
                return $deny('mac_mismatch');
            }
        }
        return ['allow' => true, 'reason' => $adapter->isPpp() ? 'ppp_credentials_required' : 'trusted_local_dhcp'];
    }
    public static function capability(?array $profile, string $action = 'authorize'): ?string
    {
        if (!$profile) {
            return 'Select and validate a NAS/access profile.';
        }
        if (empty($profile['enabled']) || empty($profile['authorization_verified']) || empty($profile['software_version']) || empty($profile['model']) || empty($profile['evidence'])) {
            return 'Exact model/release and authorization evidence are required.';
        }
        if ($action !== 'authorize' && empty($profile['actions_verified'])) {
            return 'Session controls are unverified on this model/release.';
        }
        if ($action !== 'authorize' && !self::adapter($profile['adapter'])->isPpp() && empty($profile['forwarding_verified'])) {
            return 'DHCP forwarding enforcement is unverified; lease deletion is insufficient.';
        }
        return null;
    }
}
