-- Per-sensor temperature history recorded by bin/poll-snmp.php (about one row per sensor per
-- minute, 7 days). sampled_at is a Unix timestamp, like nas_snmp_samples.
CREATE TABLE IF NOT EXISTS nas_snmp_temperature_samples (
  nas_id INT(10) NOT NULL,
  sensor VARCHAR(80) NOT NULL,
  sampled_at INT UNSIGNED NOT NULL,
  celsius DECIMAL(5,1) NOT NULL,
  PRIMARY KEY (nas_id, sensor, sampled_at),
  INDEX idx_nas_snmp_temperature_time (nas_id, sampled_at),
  INDEX idx_nas_snmp_temperature_retention (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
