-- SNMP monitoring settings live apart from nas_metadata so saving them never bumps the
-- RADIUS configuration revision or marks the NAS as pending deployment.
CREATE TABLE IF NOT EXISTS nas_snmp (
  nas_id INT(10) NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  host VARCHAR(190) NULL,
  port INT NOT NULL DEFAULT 161,
  version ENUM('1','2c') NOT NULL DEFAULT '2c',
  community VARCHAR(255) NULL,
  timeout_ms INT NOT NULL DEFAULT 1500,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
