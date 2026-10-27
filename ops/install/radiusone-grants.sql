-- Database permissions for RadiusOne. Applied by install.sh and radiusone-update as the MariaDB administrator after the
-- migrations, because the tables must exist first. One least-privilege identity per role, as in production:
--   radiusone_portal     the web app and its scheduled jobs
--   radiusone_radius     FreeRADIUS (reads users, groups and NAS clients; writes accounting and post-auth)
--   radiusone_migration  radiusone:migrate (schema changes)
--   radiusone_backup     radiusone:backup (read-only)
-- The users themselves (and their passwords) are created by install.sh.

-- radiusone_portal
GRANT SELECT, INSERT ON `radius`.`audit_logs` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`customer_profiles` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`service_metadata` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nas_access_profiles` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`static_ip_pools` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`portal_users` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`uploaded_documents` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`policy_revisions` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`nas_snmp_temperature_samples` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`nas_snmp_health_samples` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`settings` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`billing_invoices` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`notification_jobs` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radreply` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`job_runs` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nas_snmp` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`access_policies` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`billing_invoice_items` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`customer_identity_history` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`billing_accounts` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`notification_deliveries` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`network_operations` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`notification_onboarding` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`billing_payments` TO `radiusone_portal`@`localhost`;
GRANT SELECT, DELETE ON `radius`.`radacct` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`notification_logs` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radcheck` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`subscriptions` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`usage_daily` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`usage_session_marks` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`usage_cycles` TO `radiusone_portal`@`localhost`;
GRANT SELECT (`reply`, `authdate`, `username`), DELETE ON `radius`.`radpostauth` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radgroupreply` TO `radiusone_portal`@`localhost`;
GRANT SELECT ON `radius`.`access_auth_events` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`notification_events` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nas` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nas_metadata` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radgroupcheck` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`nas_snmp_samples` TO `radiusone_portal`@`localhost`;
GRANT SELECT ON `radius`.`address_assignments` TO `radiusone_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radusergroup` TO `radiusone_portal`@`localhost`;

-- radiusone_radius
GRANT INSERT ON `radius`.`radpostauth` TO `radiusone_radius`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`radacct` TO `radiusone_radius`@`localhost`;
GRANT SELECT ON `radius`.`nas` TO `radiusone_radius`@`localhost`;
GRANT SELECT ON `radius`.`radgroupcheck` TO `radiusone_radius`@`localhost`;
GRANT SELECT ON `radius`.`access_policies` TO `radiusone_radius`@`localhost`;
GRANT SELECT ON `radius`.`radcheck` TO `radiusone_radius`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nasreload` TO `radiusone_radius`@`localhost`;
GRANT SELECT (`nas_id`, `enabled`, `pending_delete`) ON `radius`.`nas_metadata` TO `radiusone_radius`@`localhost`;
GRANT SELECT ON `radius`.`radusergroup` TO `radiusone_radius`@`localhost`;
GRANT SELECT ON `radius`.`radgroupreply` TO `radiusone_radius`@`localhost`;
GRANT SELECT ON `radius`.`radreply` TO `radiusone_radius`@`localhost`;
GRANT INSERT ON `radius`.`access_auth_events` TO `radiusone_radius`@`localhost`;

-- radiusone_migration
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER ON `radius`.* TO `radiusone_migration`@`localhost`;

-- radiusone_backup
GRANT SELECT, SHOW VIEW, TRIGGER ON `radius`.* TO `radiusone_backup`@`localhost`;
