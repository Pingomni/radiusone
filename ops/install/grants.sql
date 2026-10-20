-- Database permissions for RadiusWeb. Applied by install.sh as the MariaDB administrator after the
-- migrations, because the tables must exist first. One least-privilege identity per role, as in production:
--   frweb_portal     the web app and its scheduled jobs
--   frweb_radius     FreeRADIUS (reads users, groups and NAS clients; writes accounting and post-auth)
--   frweb_migration  frweb:migrate (schema changes)
--   frweb_backup     frweb:backup (read-only)
-- The users themselves (and their passwords) are created by install.sh.

-- frweb_portal
GRANT SELECT, INSERT ON `radius`.`audit_logs` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`customer_profiles` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`service_metadata` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nas_access_profiles` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`static_ip_pools` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`portal_users` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`uploaded_documents` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`policy_revisions` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`nas_snmp_temperature_samples` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`nas_snmp_health_samples` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`settings` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`billing_invoices` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`notification_jobs` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radreply` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`job_runs` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nas_snmp` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`access_policies` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`billing_invoice_items` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`customer_identity_history` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`billing_accounts` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`notification_deliveries` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`network_operations` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`notification_onboarding` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`billing_payments` TO `frweb_portal`@`localhost`;
GRANT SELECT, DELETE ON `radius`.`radacct` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT ON `radius`.`notification_logs` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radcheck` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`subscriptions` TO `frweb_portal`@`localhost`;
GRANT SELECT (`reply`, `authdate`, `username`), DELETE ON `radius`.`radpostauth` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radgroupreply` TO `frweb_portal`@`localhost`;
GRANT SELECT ON `radius`.`access_auth_events` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`notification_events` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nas` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nas_metadata` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radgroupcheck` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`nas_snmp_samples` TO `frweb_portal`@`localhost`;
GRANT SELECT ON `radius`.`address_assignments` TO `frweb_portal`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `radius`.`radusergroup` TO `frweb_portal`@`localhost`;

-- frweb_radius
GRANT INSERT ON `radius`.`radpostauth` TO `frweb_radius`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`radacct` TO `frweb_radius`@`localhost`;
GRANT SELECT ON `radius`.`nas` TO `frweb_radius`@`localhost`;
GRANT SELECT ON `radius`.`radgroupcheck` TO `frweb_radius`@`localhost`;
GRANT SELECT ON `radius`.`access_policies` TO `frweb_radius`@`localhost`;
GRANT SELECT ON `radius`.`radcheck` TO `frweb_radius`@`localhost`;
GRANT SELECT, INSERT, UPDATE ON `radius`.`nasreload` TO `frweb_radius`@`localhost`;
GRANT SELECT (`nas_id`, `enabled`, `pending_delete`) ON `radius`.`nas_metadata` TO `frweb_radius`@`localhost`;
GRANT SELECT ON `radius`.`radusergroup` TO `frweb_radius`@`localhost`;
GRANT SELECT ON `radius`.`radgroupreply` TO `frweb_radius`@`localhost`;
GRANT SELECT ON `radius`.`radreply` TO `frweb_radius`@`localhost`;
GRANT INSERT ON `radius`.`access_auth_events` TO `frweb_radius`@`localhost`;

-- frweb_migration
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER ON `radius`.* TO `frweb_migration`@`localhost`;

-- frweb_backup
GRANT SELECT, SHOW VIEW, TRIGGER ON `radius`.* TO `frweb_backup`@`localhost`;
