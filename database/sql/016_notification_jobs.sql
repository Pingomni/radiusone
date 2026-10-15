-- Review only: apply with the migration identity after staging acceptance.
CREATE TABLE notification_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('sms','email') NOT NULL,
  expiry_date DATE NOT NULL,
  due_at DATETIME NOT NULL COMMENT 'UTC; immutable reminder due time',
  next_attempt_at DATETIME NOT NULL,
  state ENUM('pending','sending','sent','failed','uncertain','cancelled') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  claim_token VARCHAR(64) NULL,
  claimed_at DATETIME NULL,
  completed_at DATETIME NULL,
  error_class VARCHAR(190) NULL,
  UNIQUE KEY uq_reminder(customer_id,channel,expiry_date),
  CONSTRAINT fk_reminder_customer FOREIGN KEY(customer_id) REFERENCES customer_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
