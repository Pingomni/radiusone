<?php

declare(strict_types=1);

namespace App\Http\Support;

/** Operator (portal user) presentation shared by Profile and Settings. */
final class Operators
{
    public const ROLES = ['superadmin' => 'superadmin', 'admin' => 'admin', 'view' => 'view', 'support' => 'support', 'noc' => 'NOC', 'billing' => 'billing', 'security' => 'security'];

    /** Roles that may not turn two-factor authentication off. */
    public const PRIVILEGED = ['superadmin', 'admin', 'noc', 'billing', 'security'];

    /** @return list<array{role: string, description: string}> */
    public static function roleDescriptions(): array
    {
        $rows = [
            ['superadmin', 'Full access including users, NAS, services, clients, deletion, settings, backups, logs'],
            ['admin', 'Manage legacy network and billing workflows. Use specialized roles for new staff.'],
            ['support', 'Read connection diagnostics; no network or billing changes.'],
            ['noc', 'Provision services and review network operations; no payment entry.'],
            ['billing', 'Invoices and payments; cannot remove manual or security restrictions.'],
            ['security', 'Apply and lift security restrictions; no plan or payment changes.'],
            ['view', 'Read-only access to clients, services, NAS, accounting, logs, and reports.'],
        ];
        return array_map(static fn (array $r) => ['role' => $r[0], 'description' => $r[1]], $rows);
    }

    public static function twoFactorLabel(array $user): string
    {
        if (!empty($user['two_factor_enabled'])) {
            return 'enabled';
        }
        return !empty($user['two_factor_secret']) ? 'setup required' : 'off';
    }

    public static function twoFactorTag(array $user): array
    {
        $label = self::twoFactorLabel($user);
        return Present::tag($label, $label === 'enabled' ? 'success' : ($label === 'setup required' ? 'warning' : ''));
    }
}
