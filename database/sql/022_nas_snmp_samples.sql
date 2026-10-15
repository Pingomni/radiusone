-- Per-interface SNMP traffic history recorded by bin/poll-snmp.php (about one row per interface
-- per minute, kept for 7 days). sampled_at is a Unix timestamp so bucketing never depends on the
-- database session time zone. Counters are kept so the next run can derive rates.
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
