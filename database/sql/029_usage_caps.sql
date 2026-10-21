-- Usage history and data caps.
-- usage_daily: download/upload per RADIUS username and day, built every few minutes from the growth of each
-- session's counters (usage_session_marks holds the counters last seen per session).
CREATE TABLE IF NOT EXISTS usage_daily (
  username VARCHAR(64) NOT NULL,
  day DATE NOT NULL,
  down BIGINT UNSIGNED NOT NULL DEFAULT 0,
  up BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (username, day),
  KEY idx_usage_daily_day (day)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usage_session_marks (
  radacctid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  down BIGINT UNSIGNED NOT NULL DEFAULT 0,
  up BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seen_at DATETIME NOT NULL,
  KEY idx_usage_marks_seen (seen_at)
) ENGINE=InnoDB;

-- One row per customer on a capped plan: the current cycle, what was used in it, and which alerts were sent.
CREATE TABLE IF NOT EXISTS usage_cycles (
  customer_id INT NOT NULL PRIMARY KEY,
  cycle_start DATE NOT NULL,
  cycle_end DATE NOT NULL,
  cap_bytes BIGINT UNSIGNED NOT NULL,
  used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  warned TINYINT(1) NOT NULL DEFAULT 0,
  reached TINYINT(1) NOT NULL DEFAULT 0,
  capped TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- Per plan: total_quota (existing, unused until now) holds the cap in GB per customer cycle;
-- cap_action is notify (messages only) or throttle (move to cap_service_group until the next cycle).
ALTER TABLE service_metadata ADD COLUMN IF NOT EXISTS cap_action VARCHAR(16) NOT NULL DEFAULT 'notify' AFTER total_quota;
ALTER TABLE service_metadata ADD COLUMN IF NOT EXISTS cap_service_group VARCHAR(64) NULL AFTER cap_action;
