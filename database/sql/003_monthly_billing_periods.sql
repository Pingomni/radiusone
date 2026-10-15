UPDATE service_metadata
SET billing_period = 'monthly'
WHERE billing_period IN ('daily', 'weekly', 'custom');

ALTER TABLE service_metadata
  MODIFY COLUMN billing_period ENUM('monthly','2_months','3_months','6_months','yearly') NOT NULL DEFAULT 'monthly';
