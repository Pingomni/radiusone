-- Traffic history per RADIUS username: how much each client downloaded and uploaded in every five minutes,
-- from the same counter growth that fills usage_daily (UsageCaps::collect). The client page draws it as a rate
-- (bit/s) for the last 24 hours and 7 days; rows older than eight days are removed by the collector.
CREATE TABLE IF NOT EXISTS usage_traffic (
  username VARCHAR(64) NOT NULL,
  at DATETIME NOT NULL,
  down BIGINT UNSIGNED NOT NULL DEFAULT 0,
  up BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (username, at),
  KEY idx_usage_traffic_at (at)
) ENGINE=InnoDB;
