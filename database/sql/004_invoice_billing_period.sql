ALTER TABLE billing_invoices
  ADD COLUMN IF NOT EXISTS billing_period ENUM('monthly','2_months','3_months','6_months','yearly') NOT NULL DEFAULT 'monthly' AFTER service_group;
