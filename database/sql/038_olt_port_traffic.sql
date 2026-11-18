-- Each OLT port's traffic over time, for a graph per port (kept for 8 days, like the other OLT graphs).
CREATE TABLE IF NOT EXISTS olt_port_samples (
  olt_id INT UNSIGNED NOT NULL,
  if_index BIGINT UNSIGNED NOT NULL,
  sampled_at INT UNSIGNED NOT NULL,
  in_bps DOUBLE NULL,
  out_bps DOUBLE NULL,
  PRIMARY KEY (olt_id, if_index, sampled_at),
  KEY idx_olt_port_samples_retention (sampled_at),
  CONSTRAINT fk_olt_port_samples_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
