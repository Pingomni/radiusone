-- OLTs and their ONUs, read-only. Passwords and the SNMP community are sealed by App\Support\Secrets.
-- olt_onus and olt_macs are what the OLT last reported, replaced by each sync. customer_onus is the map between a
-- client and an ONU: it holds the serial number, so it survives the ONU moving to another port.
CREATE TABLE IF NOT EXISTS olts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  model VARCHAR(40) NOT NULL,
  host VARCHAR(45) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  snmp_version ENUM('1','2c','3') NOT NULL DEFAULT '2c',
  snmp_port INT UNSIGNED NOT NULL DEFAULT 161,
  snmp_community VARCHAR(255) NULL,
  v3_user VARCHAR(64) NULL,
  v3_auth_protocol VARCHAR(16) NOT NULL DEFAULT 'none',
  v3_auth_password VARCHAR(255) NULL,
  v3_priv_protocol VARCHAR(16) NOT NULL DEFAULT 'none',
  v3_priv_password VARCHAR(255) NULL,
  v3_context VARCHAR(64) NULL,
  telnet_enabled TINYINT(1) NOT NULL DEFAULT 0,
  telnet_port INT UNSIGNED NOT NULL DEFAULT 23,
  telnet_user VARCHAR(255) NULL,
  telnet_password VARCHAR(255) NULL,
  sync_requested_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  last_sync_ok TINYINT(1) NULL,
  last_error VARCHAR(500) NULL,
  last_mac_sync_at DATETIME NULL,
  last_mac_ok TINYINT(1) NULL,
  last_mac_error VARCHAR(500) NULL,
  system_description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_olts_host (host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olt_onus (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  olt_id INT UNSIGNED NOT NULL,
  frame SMALLINT UNSIGNED NOT NULL,
  slot SMALLINT UNSIGNED NOT NULL,
  port SMALLINT UNSIGNED NOT NULL,
  ont_id SMALLINT UNSIGNED NOT NULL,
  sn VARCHAR(32) NOT NULL,
  description VARCHAR(190) NOT NULL DEFAULT '',
  onu_model VARCHAR(64) NOT NULL DEFAULT '',
  line_profile VARCHAR(64) NOT NULL DEFAULT '',
  service_profile VARCHAR(64) NOT NULL DEFAULT '',
  status VARCHAR(12) NOT NULL DEFAULT 'unknown',
  down_cause VARCHAR(64) NOT NULL DEFAULT '',
  last_up_at VARCHAR(19) NULL,
  last_down_at VARCHAR(19) NULL,
  distance_m INT NULL,
  mac_count INT NULL,
  rx_dbm DECIMAL(6,2) NULL,
  olt_rx_dbm DECIMAL(6,2) NULL,
  temperature_c SMALLINT NULL,
  seen_at DATETIME NOT NULL,
  UNIQUE KEY uq_olt_onus_position (olt_id, frame, slot, port, ont_id),
  KEY idx_olt_onus_sn (sn),
  KEY idx_olt_onus_status (olt_id, status),
  CONSTRAINT fk_olt_onus_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olt_macs (
  olt_id INT UNSIGNED NOT NULL,
  mac CHAR(12) NOT NULL,
  vlan INT UNSIGNED NOT NULL,
  frame SMALLINT UNSIGNED NOT NULL,
  slot SMALLINT UNSIGNED NOT NULL,
  port SMALLINT UNSIGNED NOT NULL,
  ont_id SMALLINT UNSIGNED NOT NULL,
  seen_at DATETIME NOT NULL,
  PRIMARY KEY (olt_id, mac, vlan),
  KEY idx_olt_macs_mac (mac),
  CONSTRAINT fk_olt_macs_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_onus (
  customer_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  olt_id INT UNSIGNED NOT NULL,
  sn VARCHAR(32) NOT NULL,
  source VARCHAR(12) NOT NULL,
  evidence VARCHAR(190) NOT NULL DEFAULT '',
  linked_by VARCHAR(80) NOT NULL DEFAULT '',
  linked_at DATETIME NOT NULL,
  KEY idx_customer_onus_sn (olt_id, sn),
  CONSTRAINT fk_customer_onus_customer FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_onus_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
