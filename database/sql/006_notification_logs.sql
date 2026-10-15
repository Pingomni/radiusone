CREATE TABLE IF NOT EXISTS notification_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  channel ENUM('sms','email') NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  expiry_date DATE NULL,
  status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
  message TEXT NULL,
  error TEXT NULL,
  sent_at DATETIME NOT NULL,
  INDEX idx_notification_user_event (username, event_type, expiry_date, channel),
  INDEX idx_notification_sent_at (sent_at),
  CONSTRAINT fk_notification_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
