<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\Auth;

/**
 * Short-lived form drafts kept across a re-authentication detour (legacy FormRecovery). Only the listed
 * presentation fields are kept: never passwords, codes or files, and nothing is submitted automatically.
 */
final class FormDrafts
{
    private const FIELDS = [
        'client' => ['auth_method', 'username', 'service_group', 'account_status', 'expiry_date',
            'email_alert', 'sms_alert', 'mac_address', 'mac_lock', 'nas_restriction_type', 'nas_restriction_value',
            'framed_ip_address', 'first_name', 'last_name', 'company_name', 'address', 'city',
            'state', 'zip', 'country', 'phone', 'cell_phone', 'email', 'vat_tax_id', 'contract_id', 'contract_valid_until', 'gps', 'language', 'notes',
            'access_profile_id', 'dhcp_client_id', 'circuit_id'],
        'invoice' => ['username', 'service_group', 'static_ip_pool_key', 'billing_period', 'description', 'currency',
            'discount_amount', 'tax_amount', 'issue_date', 'status', 'notes'],
    ];

    private const BOOLEANS = ['email_alert', 'sms_alert', 'mac_lock'];

    public static function remember(string $kind, string $path, array $input, string $tab = ''): void
    {
        $values = [];
        foreach (self::FIELDS[$kind] as $name) {
            if (in_array($name, self::BOOLEANS, true)) {
                $values[$name] = !empty($input[$name]);
            } elseif (isset($input[$name]) && is_scalar($input[$name])) {
                $values[$name] = mb_substr((string) $input[$name], 0, 5000);
            }
        }
        $drafts = array_filter(session('form_drafts', []), static fn ($d) => ($d['expires'] ?? 0) >= time());
        $drafts[self::key($kind, $path)] = ['values' => $values, 'tab' => $tab, 'user_id' => Auth::id(), 'version' => session('session_version'), 'expires' => time() + 900];
        session()->put('form_drafts', array_slice($drafts, -4, null, true));
    }

    /** The draft for this form, once: it is removed as it is read. */
    public static function take(string $kind, string $path): ?array
    {
        $key = self::key($kind, $path);
        $drafts = session('form_drafts', []);
        $draft = $drafts[$key] ?? null;
        if ($draft === null) {
            return null;
        }
        unset($drafts[$key]);
        session()->put('form_drafts', $drafts);
        if ($draft['user_id'] !== Auth::id() || $draft['version'] !== session('session_version') || $draft['expires'] < time()) {
            return null;
        }
        return ['values' => $draft['values'], 'tab' => $draft['tab']];
    }

    private static function key(string $kind, string $path): string
    {
        return $kind . ':' . hash('sha256', $path);
    }
}
