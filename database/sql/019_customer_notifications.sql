-- Additive event outbox. Existing customers are intentionally not enrolled for Welcome.
CREATE TABLE notification_onboarding (
 customer_id BIGINT UNSIGNED PRIMARY KEY,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 activated_at DATETIME NULL,
 CONSTRAINT fk_notice_onboarding_customer FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notification_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 template_key ENUM('welcome','extended','custom') NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notice_event (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notification_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_id BIGINT UNSIGNED NOT NULL,
 customer_id BIGINT UNSIGNED NOT NULL,
 channel ENUM('email','sms') NOT NULL,
 recipient VARCHAR(254) NOT NULL,
 subject VARCHAR(998) NOT NULL DEFAULT '',
 body TEXT NOT NULL,
 expiry_date DATE NULL,
 state ENUM('pending','sending','sent','uncertain','skipped') NOT NULL DEFAULT 'pending',
 reason VARCHAR(80) NULL,
 claim_token CHAR(32) CHARACTER SET ascii NULL,
 claimed_at DATETIME NULL,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notice_delivery (event_id,customer_id,channel),
 KEY ix_notice_work (state,id),
 UNIQUE KEY uq_notice_claim (claim_token),
 CONSTRAINT fk_notice_delivery_event FOREIGN KEY (event_id) REFERENCES notification_events(id) ON DELETE RESTRICT,
 CONSTRAINT fk_notice_delivery_customer FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
