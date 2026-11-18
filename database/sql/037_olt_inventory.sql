-- More of what an OLT says about itself: fan speed, power use and uptime over SNMP; its ports' optics and settings, VLANs,
-- profiles and time servers over telnet (olts.inventory, as JSON, replaced by each read); and dated copies of its
-- configuration. A configuration holds the OLT's own secrets, so each copy is sealed by App\Support\Secrets.
ALTER TABLE olts
  ADD COLUMN IF NOT EXISTS power_w INT NULL AFTER last_hw_error,
  ADD COLUMN IF NOT EXISTS booted_at DATETIME NULL AFTER power_w,
  ADD COLUMN IF NOT EXISTS inventory MEDIUMTEXT NULL AFTER booted_at,
  ADD COLUMN IF NOT EXISTS last_inventory_at DATETIME NULL AFTER inventory,
  ADD COLUMN IF NOT EXISTS last_inventory_error VARCHAR(500) NULL AFTER last_inventory_at,
  ADD COLUMN IF NOT EXISTS last_backup_at DATETIME NULL AFTER last_inventory_error,
  ADD COLUMN IF NOT EXISTS last_backup_error VARCHAR(500) NULL AFTER last_backup_at;

ALTER TABLE olt_fans ADD COLUMN IF NOT EXISTS speed_rpm INT NULL AFTER speed_pct;
ALTER TABLE olt_boards ADD COLUMN IF NOT EXISTS power_w INT NULL AFTER temperature_c;

CREATE TABLE IF NOT EXISTS olt_config_backups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  olt_id INT UNSIGNED NOT NULL,
  taken_at DATETIME NOT NULL,
  checked_at DATETIME NOT NULL,
  bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  KEY idx_olt_config_backups_olt (olt_id, taken_at),
  CONSTRAINT fk_olt_config_backups_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
