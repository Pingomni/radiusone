-- Additive only: legacy financial, identity and raw accounting records remain intact.
ALTER TABLE portal_users MODIFY role ENUM('superadmin','admin','view','support','noc','billing','security') NOT NULL DEFAULT 'view';
ALTER TABLE service_metadata ADD policy_revision BIGINT UNSIGNED NOT NULL DEFAULT 1;
CREATE TABLE nas_access_profiles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 nas_id INT NOT NULL,
 adapter ENUM('routeros_pppoe','routeros_dhcp','iosxe_pppoe','iosxe_ipoe') NOT NULL,
 model VARCHAR(120) NOT NULL DEFAULT '',
 software_version VARCHAR(80) NOT NULL DEFAULT '',
 source_ip VARCHAR(45) NOT NULL,
 listener_key VARCHAR(80) NOT NULL,
 dhcp_server VARCHAR(120) NOT NULL DEFAULT '',
 expected_interval INT UNSIGNED NOT NULL DEFAULT 300,
 grace_seconds INT UNSIGNED NOT NULL DEFAULT 120,
 enabled TINYINT NOT NULL DEFAULT 0,
 authorization_verified TINYINT NOT NULL DEFAULT 0,
 actions_verified TINYINT NOT NULL DEFAULT 0,
 forwarding_verified TINYINT NOT NULL DEFAULT 0,
 evidence VARCHAR(500) NOT NULL DEFAULT '',
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 UNIQUE KEY uq_access_listener (source_ip,listener_key),
 INDEX idx_access_nas (nas_id)
) ENGINE=InnoDB;
CREATE TABLE billing_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 legacy_customer_id BIGINT UNSIGNED NOT NULL UNIQUE,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB;
INSERT INTO billing_accounts (legacy_customer_id,created_at) SELECT id,created_at FROM customer_profiles;
-- A legacy profile remains the first connection. New connections can share a billing account.
CREATE TABLE subscriptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 customer_id BIGINT UNSIGNED NOT NULL UNIQUE,
 billing_account_id BIGINT UNSIGNED NOT NULL,
 site_label VARCHAR(120) NOT NULL DEFAULT '',
 access_profile_id BIGINT UNSIGNED NULL,
 dhcp_client_id VARCHAR(253) COLLATE utf8mb4_bin NULL,
 circuit_id VARCHAR(190) COLLATE utf8mb4_bin NULL,
 suspension_source ENUM('manual','security','commercial') NULL,
 policy_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 policy_hash CHAR(64) NULL,
 service_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 dirty TINYINT NOT NULL DEFAULT 1,
 next_transition_at DATETIME NULL,
 provisioning_state VARCHAR(32) NOT NULL DEFAULT 'pending',
 last_error VARCHAR(190) NULL,
 applied_revision BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE RESTRICT,
 FOREIGN KEY (billing_account_id) REFERENCES billing_accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY (access_profile_id) REFERENCES nas_access_profiles(id) ON DELETE RESTRICT,
 INDEX idx_subscription_due (dirty,next_transition_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO subscriptions (customer_id,billing_account_id,suspension_source,created_at,updated_at)
 SELECT c.id,b.id,IF(c.account_status IN ('suspended','disabled'),'manual',NULL),c.created_at,c.updated_at
 FROM customer_profiles c JOIN billing_accounts b ON b.legacy_customer_id=c.id;
CREATE TABLE policy_revisions (
 subscription_id BIGINT UNSIGNED NOT NULL,
 revision BIGINT UNSIGNED NOT NULL,
 policy_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (subscription_id,revision),
 FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE network_operations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subscription_id BIGINT UNSIGNED NOT NULL,
 policy_revision BIGINT UNSIGNED NOT NULL,
 action ENUM('apply','suspend','restore','disconnect') NOT NULL,
 dedupe_key VARCHAR(190) NOT NULL UNIQUE,
 state ENUM('pending','running','ack','nak','timeout','uncertain','verified','blocked','superseded','failed') NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL,
 claimed_at DATETIME NULL,
 session_json LONGTEXT NULL,
 result_code VARCHAR(120) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 FOREIGN KEY (subscription_id,policy_revision) REFERENCES policy_revisions(subscription_id,revision) ON DELETE RESTRICT,
 INDEX idx_operations_due (state,available_at,id)
) ENGINE=InnoDB;
-- AAA reads only this publication table, not billing or portal tables. No credentials here.
CREATE TABLE access_policies (
 username VARCHAR(64) NOT NULL PRIMARY KEY,
 subscription_id BIGINT UNSIGNED NOT NULL,
 revision BIGINT UNSIGNED NOT NULL,
 adapter VARCHAR(32) NOT NULL,
 source_ip VARCHAR(45) NOT NULL,
 listener_key VARCHAR(80) NOT NULL,
 circuit_id VARCHAR(190) COLLATE utf8mb4_bin NOT NULL DEFAULT '',
 dhcp_server VARCHAR(120) COLLATE utf8mb4_bin NOT NULL DEFAULT '',
 dhcp_client_id VARCHAR(253) COLLATE utf8mb4_bin NULL,
 mac_address VARCHAR(17) NOT NULL DEFAULT '',
 allowed TINYINT NOT NULL DEFAULT 0,
 valid_until BIGINT UNSIGNED NULL,
 reason VARCHAR(120) NOT NULL,
 INDEX idx_policy_context (source_ip,listener_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Reserved path for future dual-stack allocation. No allocator/reply emission is enabled.
CREATE TABLE address_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subscription_id BIGINT UNSIGNED NOT NULL,
 family ENUM('ipv4','ipv6') NOT NULL,
 purpose ENUM('wan','delegated_prefix') NOT NULL,
 address VARBINARY(16) NOT NULL,
 prefix_length TINYINT UNSIGNED NOT NULL,
 allocated_at DATETIME NOT NULL,
 released_at DATETIME NULL,
 active_key VARCHAR(80) GENERATED ALWAYS AS (IF(released_at IS NULL,CONCAT(family,':',HEX(address),'/',prefix_length),NULL)) STORED,
 UNIQUE KEY uq_active_address (active_key),
 FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
-- New decisions are recorded separately; historical radpostauth rows remain unchanged.
CREATE TABLE access_auth_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(64) NOT NULL,
 source_ip VARCHAR(45) NOT NULL,
 adapter VARCHAR(32) NOT NULL,
 decision ENUM('accept','reject') NOT NULL,
 reason VARCHAR(120) NOT NULL,
 occurred_at DATETIME NOT NULL,
 INDEX idx_access_auth_identity (username,id),
 INDEX idx_access_auth_time (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
