-- Per-NAS CPU and memory history recorded by bin/poll-snmp.php (about one row per minute, 7 days).
-- sampled_at is a Unix timestamp, like nas_snmp_samples.
CREATE TABLE IF NOT EXISTS nas_snmp_health_samples (
  nas_id INT(10) NOT NULL,
  sampled_at INT UNSIGNED NOT NULL,
  cpu_pct DECIMAL(5,2) NULL,
  mem_used DECIMAL(20,0) NULL,
  mem_total DECIMAL(20,0) NULL,
  PRIMARY KEY (nas_id, sampled_at),
  INDEX idx_nas_snmp_health_retention (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
