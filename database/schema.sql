-- ============================================================
-- WebRadius application schema (consolidated dump)
-- https://github.com/n-geraldo/webradius
--
-- Import this into the SAME database that holds the FreeRADIUS
-- SQL tables (default database name: radius), AFTER importing
-- the official FreeRADIUS schema:
--   /etc/freeradius/3.0/mods-config/sql/main/mysql/schema.sql
--
-- Usage:
--   mysql -u root -p radius < database/schema.sql
--
-- Fresh-install snapshot only: existing tables are not upgraded.
-- Prefer bin/migrate.php for tracked deployment; snapshot imports
-- require an explicitly reviewed migration-ledger baseline.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Portal operator accounts (login users of the web portal)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS portal_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin','admin','view') NOT NULL DEFAULT 'view',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  language VARCHAR(8) NULL,
  two_factor_secret VARCHAR(255) NULL,
  two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
  session_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  mfa_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  mfa_locked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
  mfa_last_counter BIGINT NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_portal_users_role (role),
  INDEX idx_portal_users_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Service plan metadata (paired with radgroupcheck/radgroupreply)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_metadata (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_name VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'PPPoE',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  download_speed VARCHAR(60) NULL,
  upload_speed VARCHAR(60) NULL,
  mikrotik_rate_limit VARCHAR(180) NULL,
  burst_limit VARCHAR(80) NULL,
  burst_threshold VARCHAR(80) NULL,
  burst_time VARCHAR(80) NULL,
  priority INT NULL,
  ip_mode ENUM('pool','static','none') NOT NULL DEFAULT 'pool',
  ip_pool VARCHAR(120) NULL,
  static_ip_pool TEXT NULL,
  session_timeout INT NULL,
  idle_timeout INT NULL,
  simultaneous_use INT NULL,
  download_quota VARCHAR(60) NULL,
  upload_quota VARCHAR(60) NULL,
  total_quota VARCHAR(60) NULL,
  expiration_behavior VARCHAR(120) NULL,
  billing_mode ENUM('prepaid','postpaid') NOT NULL DEFAULT 'prepaid',
  expired_service_group VARCHAR(64) NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  billing_period ENUM('monthly','2_months','3_months','6_months','yearly') NOT NULL DEFAULT 'monthly',
  custom_group_attrs TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_service_enabled (enabled),
  INDEX idx_service_expired_group (expired_service_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Static IP pools (per-client static IP assignment + pricing)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS static_ip_pools (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pool_key VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  ip_ranges TEXT NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_static_ip_pools_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Internet customer profiles (paired with radcheck/radreply/radusergroup)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  auth_method ENUM('pppoe','dhcp') NOT NULL DEFAULT 'pppoe',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  email_alert TINYINT(1) NOT NULL DEFAULT 0,
  sms_alert TINYINT(1) NOT NULL DEFAULT 0,
  account_status VARCHAR(40) NOT NULL DEFAULT 'active',
  archived_at DATETIME NULL,
  expiry_date DATE NULL,
  billing_anchor_day TINYINT UNSIGNED NULL,
  available_online_time INT NULL,
  simultaneous_use INT NULL,
  service_group VARCHAR(64) NULL,
  owner_operator_id BIGINT UNSIGNED NULL,
  ip_mode ENUM('pool','static','none') NOT NULL DEFAULT 'pool',
  framed_ip_address VARCHAR(45) NULL,
  allocated_static_ip VARCHAR(45) GENERATED ALWAYS AS (NULLIF(framed_ip_address, '')) STORED,
  UNIQUE KEY uq_customer_static_ip (allocated_static_ip),
  framed_pool VARCHAR(120) NULL,
  mac_address VARCHAR(80) NULL,
  mac_lock TINYINT(1) NOT NULL DEFAULT 0,
  nas_restriction_type ENUM('all','nas_ip','nas_identifier') NOT NULL DEFAULT 'all',
  nas_restriction_value VARCHAR(190) NULL,
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  company_name VARCHAR(180) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  zip VARCHAR(40) NULL,
  country VARCHAR(100) NULL,
  phone VARCHAR(80) NULL,
  cell_phone VARCHAR(80) NULL,
  email VARCHAR(190) NULL,
  vat_tax_id VARCHAR(80) NULL,
  contract_id VARCHAR(80) NULL,
  contract_valid_until DATE NULL,
  gps VARCHAR(120) NULL,
  language VARCHAR(20) NULL,
  notes TEXT NULL,
  download_limit VARCHAR(60) NULL,
  upload_limit VARCHAR(60) NULL,
  total_traffic_limit VARCHAR(60) NULL,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  invoice_alert TINYINT(1) NOT NULL DEFAULT 0,
  payment_status VARCHAR(40) NULL,
  billing_mode ENUM('prepaid','postpaid') NOT NULL DEFAULT 'prepaid',
  custom_radius_attributes TEXT NULL,
  registration_date DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_customer_status (enabled, account_status),
  INDEX idx_customer_service (service_group),
  INDEX idx_customer_expiry (expiry_date),
  CONSTRAINT fk_customer_owner FOREIGN KEY (owner_operator_id) REFERENCES portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Uploaded customer documents (ID scans, contracts)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uploaded_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  document_type ENUM('id_front','id_back','contract','other') NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_documents_username (username),
  CONSTRAINT fk_documents_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- NAS metadata (extends the standard FreeRADIUS `nas` table)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nas_metadata (
  nas_id INT(10) NOT NULL PRIMARY KEY,
  vendor VARCHAR(40) NOT NULL DEFAULT 'generic',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  coa_enabled TINYINT(1) NOT NULL DEFAULT 1,
  coa_port INT NOT NULL DEFAULT 3799,
  api_host VARCHAR(190) NULL,
  api_user VARCHAR(120) NULL,
  api_password VARCHAR(255) NULL,
  config_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  applied_revision BIGINT UNSIGNED NULL,
  applied_at DATETIME NULL,
  applied_evidence VARCHAR(500) NULL,
  pending_delete TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_nas_metadata_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SNMP monitoring settings; kept apart from nas_metadata so they never mark the NAS pending deployment.
CREATE TABLE IF NOT EXISTS nas_snmp (
  nas_id INT(10) NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  host VARCHAR(190) NULL,
  port INT NOT NULL DEFAULT 161,
  version ENUM('1','2c','3') NOT NULL DEFAULT '2c',
  community VARCHAR(255) NULL,
  timeout_ms INT NOT NULL DEFAULT 1500,
  v3_user VARCHAR(64) NULL,
  v3_auth_protocol VARCHAR(16) NOT NULL DEFAULT 'none',
  v3_auth_password VARCHAR(255) NULL,
  v3_priv_protocol VARCHAR(16) NOT NULL DEFAULT 'none',
  v3_priv_password VARCHAR(255) NULL,
  v3_context VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-interface SNMP traffic history (bin/poll-snmp.php, 7 days). sampled_at is a Unix timestamp.
CREATE TABLE IF NOT EXISTS nas_snmp_samples (
  nas_id INT(10) NOT NULL,
  if_index INT UNSIGNED NOT NULL,
  if_name VARCHAR(120) NOT NULL,
  sampled_at INT UNSIGNED NOT NULL,
  in_octets DECIMAL(20,0) NULL,
  out_octets DECIMAL(20,0) NULL,
  in_bps BIGINT UNSIGNED NULL,
  out_bps BIGINT UNSIGNED NULL,
  PRIMARY KEY (nas_id, if_index, sampled_at),
  INDEX idx_nas_snmp_samples_time (nas_id, sampled_at),
  INDEX idx_nas_snmp_samples_retention (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-NAS CPU and memory history (bin/poll-snmp.php, 7 days). sampled_at is a Unix timestamp.
CREATE TABLE IF NOT EXISTS nas_snmp_health_samples (
  nas_id INT(10) NOT NULL,
  sampled_at INT UNSIGNED NOT NULL,
  cpu_pct DECIMAL(5,2) NULL,
  mem_used DECIMAL(20,0) NULL,
  mem_total DECIMAL(20,0) NULL,
  PRIMARY KEY (nas_id, sampled_at),
  INDEX idx_nas_snmp_health_retention (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-sensor temperature history (bin/poll-snmp.php, 7 days). sampled_at is a Unix timestamp.
CREATE TABLE IF NOT EXISTS nas_snmp_temperature_samples (
  nas_id INT(10) NOT NULL,
  sensor VARCHAR(80) NOT NULL,
  sampled_at INT UNSIGNED NOT NULL,
  celsius DECIMAL(5,1) NOT NULL,
  PRIMARY KEY (nas_id, sensor, sampled_at),
  INDEX idx_nas_snmp_temperature_time (nas_id, sampled_at),
  INDEX idx_nas_snmp_temperature_retention (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Portal settings (branding, notification gateways, templates)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Admin audit trail
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  actor_username VARCHAR(80) NOT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id VARCHAR(190) NULL,
  ip_address VARCHAR(80) NULL,
  user_agent VARCHAR(500) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_actor (actor_id),
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_created (created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Billing: invoices
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(40) NOT NULL UNIQUE,
  identity_at_issue VARCHAR(64) NULL,
  cycle_key VARCHAR(190) NULL,
  cycle_class ENUM('manual','generated') NULL,
  billing_anchor_day TINYINT UNSIGNED NULL,
  replaces_invoice_id BIGINT UNSIGNED NULL,
  active_cycle_key VARCHAR(190) GENERATED ALWAYS AS (CASE WHEN status <> 'void' THEN cycle_key ELSE NULL END) STORED,
  UNIQUE KEY uq_billing_active_cycle (active_cycle_key),
  INDEX idx_billing_cycle_history (cycle_key),
  UNIQUE KEY uq_invoice_replacement (replaces_invoice_id),
  CONSTRAINT fk_invoice_replacement FOREIGN KEY (replaces_invoice_id) REFERENCES billing_invoices(id) ON DELETE RESTRICT,
  username VARCHAR(64) NOT NULL,
  service_group VARCHAR(64) NULL,
  billing_period ENUM('monthly','2_months','3_months','6_months','yearly') NOT NULL DEFAULT 'monthly',
  period_start DATE NULL,
  period_end DATE NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('draft','open','partial','paid','void','overdue') NOT NULL DEFAULT 'open',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_billing_invoice_customer (username),
  INDEX idx_billing_invoice_status_due (status, due_date),
  INDEX idx_billing_invoice_period (period_start, period_end),
  CONSTRAINT fk_billing_invoice_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_billing_invoice_creator FOREIGN KEY (created_by) REFERENCES portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Billing: invoice line items
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_invoice_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  service_group VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_billing_item_invoice (invoice_id),
  CONSTRAINT fk_billing_item_invoice FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Billing: payments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  username VARCHAR(64) NOT NULL,
  payment_date DATE NOT NULL,
  request_key VARCHAR(64) NULL,
  request_hash CHAR(64) NULL,
  UNIQUE KEY uq_payment_request (request_key),
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  method ENUM('cash','bank_transfer','mobile_money','card','check','other') NOT NULL DEFAULT 'cash',
  reference VARCHAR(120) NULL,
  notes TEXT NULL,
  received_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_billing_payment_invoice (invoice_id),
  INDEX idx_billing_payment_customer (username),
  INDEX idx_billing_payment_date (payment_date),
  CONSTRAINT fk_billing_payment_invoice FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_billing_payment_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_billing_payment_receiver FOREIGN KEY (received_by) REFERENCES portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- SMS / email notification log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  channel ENUM('sms','email') NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  expiry_date DATE NULL,
  status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
  message TEXT NULL,
  error TEXT NULL,
  sent_at DATETIME NOT NULL,
  INDEX idx_notification_user_event (username, event_type, expiry_date, channel),
  INDEX idx_notification_sent_at (sent_at),
  CONSTRAINT fk_notification_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_identity_history (
 username VARCHAR(64) NOT NULL PRIMARY KEY,
 customer_id BIGINT UNSIGNED NOT NULL,
 renamed_at DATETIME NOT NULL,
 CONSTRAINT fk_identity_customer FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE RESTRICT,
 INDEX idx_identity_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 job_name VARCHAR(80) NOT NULL,
 state ENUM('running','succeeded','failed','skipped') NOT NULL,
 started_at DATETIME NOT NULL,
 finished_at DATETIME NULL,
 summary_json JSON NULL,
 error_class VARCHAR(190) NULL,
 INDEX idx_job_monitor (job_name,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review only: apply with the migration identity after staging acceptance.
CREATE TABLE IF NOT EXISTS notification_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('sms','email') NOT NULL,
  expiry_date DATE NOT NULL,
  due_at DATETIME NOT NULL COMMENT 'UTC; immutable reminder due time',
  next_attempt_at DATETIME NOT NULL,
  state ENUM('pending','sending','sent','failed','uncertain','cancelled') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  claim_token VARCHAR(64) NULL,
  claimed_at DATETIME NULL,
  completed_at DATETIME NULL,
  error_class VARCHAR(190) NULL,
  UNIQUE KEY uq_reminder(customer_id,channel,expiry_date),
  CONSTRAINT fk_reminder_customer FOREIGN KEY(customer_id) REFERENCES customer_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Additive event outbox. Existing customers are intentionally not enrolled for Welcome.
CREATE TABLE notification_onboarding (
 customer_id BIGINT UNSIGNED PRIMARY KEY,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 activated_at DATETIME NULL,
 CONSTRAINT fk_notice_onboarding_customer FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notification_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 template_key ENUM('welcome','extended','custom') NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notice_event (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notification_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_id BIGINT UNSIGNED NOT NULL,
 customer_id BIGINT UNSIGNED NOT NULL,
 channel ENUM('email','sms') NOT NULL,
 recipient VARCHAR(254) NOT NULL,
 subject VARCHAR(998) NOT NULL DEFAULT '',
 body TEXT NOT NULL,
 expiry_date DATE NULL,
 state ENUM('pending','sending','sent','uncertain','skipped') NOT NULL DEFAULT 'pending',
 reason VARCHAR(80) NULL,
 claim_token CHAR(32) CHARACTER SET ascii NULL,
 claimed_at DATETIME NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notice_delivery (event_id,customer_id,channel),
 KEY ix_notice_work (state,id),
 UNIQUE KEY uq_notice_claim (claim_token),
 CONSTRAINT fk_notice_delivery_event FOREIGN KEY (event_id) REFERENCES notification_events(id) ON DELETE RESTRICT,
 CONSTRAINT fk_notice_delivery_customer FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
