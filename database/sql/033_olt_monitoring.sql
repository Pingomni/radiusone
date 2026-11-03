-- Monitoring for OLTs: when each ONU was last seen online, and ONU counts over time for the OLT's graph.
-- Kept for 8 days. sampled_at is a Unix timestamp, like nas_snmp_samples.
ALTER TABLE olt_onus ADD COLUMN IF NOT EXISTS last_online_at DATETIME NULL AFTER seen_at;

CREATE TABLE IF NOT EXISTS olt_samples (
  olt_id INT UNSIGNED NOT NULL,
  sampled_at INT UNSIGNED NOT NULL,
  onus INT UNSIGNED NOT NULL,
  online INT UNSIGNED NOT NULL,
  offline INT UNSIGNED NOT NULL,
  weak INT UNSIGNED NOT NULL,
  PRIMARY KEY (olt_id, sampled_at),
  KEY idx_olt_samples_retention (sampled_at),
  CONSTRAINT fk_olt_samples_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
