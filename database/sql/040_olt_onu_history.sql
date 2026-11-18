-- Each ONU's signal, state and traffic over time, for the graphs and the count of disconnections on its page
-- (kept for 8 days). The rows follow the serial number, so the history stays with an ONU that moves to another port.
-- olt_onus keeps the last traffic counters read, to work out the rate at the next read.
CREATE TABLE IF NOT EXISTS olt_onu_samples (
  olt_id INT UNSIGNED NOT NULL,
  sn VARCHAR(32) NOT NULL,
  sampled_at INT UNSIGNED NOT NULL,
  online TINYINT(1) NOT NULL,
  rx_dbm DECIMAL(6,2) NULL,
  olt_rx_dbm DECIMAL(6,2) NULL,
  up_bps DOUBLE NULL,
  down_bps DOUBLE NULL,
  PRIMARY KEY (olt_id, sn, sampled_at),
  KEY idx_olt_onu_samples_retention (sampled_at),
  CONSTRAINT fk_olt_onu_samples_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE olt_onus
  ADD COLUMN IF NOT EXISTS up_octets DECIMAL(24,0) NULL AFTER active,
  ADD COLUMN IF NOT EXISTS down_octets DECIMAL(24,0) NULL AFTER up_octets,
  ADD COLUMN IF NOT EXISTS counters_at INT UNSIGNED NULL AFTER down_octets;
