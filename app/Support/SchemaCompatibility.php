<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class SchemaCompatibility
{
    private const REQUIRED = [
        'notifications' => ['notification_events' => ['event_key', 'template_key'], 'notification_deliveries' => ['event_id', 'recipient', 'body', 'claim_token', 'state'], 'notification_onboarding' => ['activated_at']],
        'billing' => ['billing_invoices' => ['cycle_class', 'billing_anchor_day', 'replaces_invoice_id', 'active_cycle_key']],
        'snmp' => ['nas_snmp' => ['enabled', 'host', 'port', 'version', 'community', 'timeout_ms']],
        'snmp_history' => ['nas_snmp_samples' => ['if_index', 'if_name', 'sampled_at', 'in_octets', 'out_octets', 'in_bps', 'out_bps']],
        'snmp_health' => ['nas_snmp_health_samples' => ['sampled_at', 'cpu_pct', 'mem_used', 'mem_total']],
        'snmp_temperature' => ['nas_snmp_temperature_samples' => ['sensor', 'sampled_at', 'celsius']],
        'snmp_v3' => ['nas_snmp' => ['v3_user', 'v3_auth_protocol', 'v3_auth_password', 'v3_priv_protocol', 'v3_priv_password', 'v3_context']],
        'policy' => ['subscriptions' => ['policy_revision', 'policy_hash', 'dirty', 'next_transition_at'], 'network_operations' => ['dedupe_key', 'session_json', 'result_code'], 'access_policies' => ['listener_key', 'valid_until'], 'service_metadata' => ['policy_revision'], 'access_auth_events' => ['decision', 'reason']],
    ];
    public static function problems(string $feature): array
    {
        $missing = [];
        foreach (self::REQUIRED[$feature] ?? throw new RuntimeException('Unknown schema feature.') as $table => $columns) {
            $actual = Db::select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            foreach (array_diff($columns, array_column($actual, 'COLUMN_NAME')) as $column) {
                $missing[] = $table . '.' . $column;
            }
        }
        // Check unfinished DDL when a deployment ledger exists. Test fixtures may have no ledger.
        if (Db::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'")) {
            foreach (Db::select('SELECT filename FROM schema_migrations WHERE state<>"applied"') as $row) {
                $missing[] = 'unfinished ' . $row['filename'];
            }
        }
        return $missing;
    }
    public static function require(string $feature): void
    {
        if (self::problems($feature)) {
            throw new SchemaMismatch('Action blocked: incompatible ' . $feature . ' schema. Rehearse and deploy migration ' . match ($feature) {
                'billing' => '017','notifications' => '019','snmp' => '020','snmp_v3' => '021','snmp_history' => '022','snmp_health' => '023','snmp_temperature' => '024',default => '018'
            } . ' with this release; no live migration is automatic.');
        }
    }
}
