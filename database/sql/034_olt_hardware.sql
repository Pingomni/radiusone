-- An OLT's own hardware: its cards and ports as last read, and their history for the graphs (kept for 8 days).
ALTER TABLE olts
  ADD COLUMN IF NOT EXISTS last_hw_at DATETIME NULL AFTER last_mac_error,
  ADD COLUMN IF NOT EXISTS last_hw_error VARCHAR(500) NULL AFTER last_hw_at;

CREATE TABLE IF NOT EXISTS olt_boards (
  olt_id INT UNSIGNED NOT NULL,
  frame SMALLINT UNSIGNED NOT NULL,
  slot SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(64) NOT NULL DEFAULT '',
  status_code INT NULL,
  online_code INT NULL,
  work_mode INT NULL,
  power_input INT NULL,
  serial VARCHAR(64) NOT NULL DEFAULT '',
  cpu_pct TINYINT UNSIGNED NULL,
  mem_pct TINYINT UNSIGNED NULL,
  temperature_c SMALLINT NULL,
  seen_at DATETIME NOT NULL,
  PRIMARY KEY (olt_id, frame, slot),
  CONSTRAINT fk_olt_boards_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olt_ports (
  olt_id INT UNSIGNED NOT NULL,
  if_index BIGINT UNSIGNED NOT NULL,
  name VARCHAR(64) NOT NULL,
  alias VARCHAR(128) NOT NULL DEFAULT '',
  kind VARCHAR(8) NOT NULL,
  admin_up TINYINT(1) NULL,
  oper_up TINYINT(1) NULL,
  speed_mbps INT UNSIGNED NULL,
  in_octets DECIMAL(24,0) NULL,
  out_octets DECIMAL(24,0) NULL,
  in_bps DOUBLE NULL,
  out_bps DOUBLE NULL,
  in_errors DECIMAL(24,0) NULL,
  out_errors DECIMAL(24,0) NULL,
  sampled_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (olt_id, if_index),
  CONSTRAINT fk_olt_ports_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olt_hw_samples (
  olt_id INT UNSIGNED NOT NULL,
  sampled_at INT UNSIGNED NOT NULL,
  cpu_pct TINYINT UNSIGNED NULL,
  mem_pct TINYINT UNSIGNED NULL,
  temperature_c SMALLINT NULL,
  in_bps DOUBLE NULL,
  out_bps DOUBLE NULL,
  PRIMARY KEY (olt_id, sampled_at),
  KEY idx_olt_hw_samples_retention (sampled_at),
  CONSTRAINT fk_olt_hw_samples_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olt_fans (
  olt_id INT UNSIGNED NOT NULL,
  label VARCHAR(64) NOT NULL,
  state VARCHAR(64) NOT NULL DEFAULT '',
  ok TINYINT(1) NULL,
  speed_pct TINYINT UNSIGNED NULL,
  PRIMARY KEY (olt_id, label),
  CONSTRAINT fk_olt_fans_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
