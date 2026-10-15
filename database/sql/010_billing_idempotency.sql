-- Review existing generated cycles before deployment; no legacy invoice is rewritten here.
ALTER TABLE customer_profiles ADD COLUMN IF NOT EXISTS billing_anchor_day TINYINT UNSIGNED NULL;
ALTER TABLE billing_invoices ADD COLUMN IF NOT EXISTS cycle_key VARCHAR(190) NULL, ADD UNIQUE KEY uq_billing_cycle (cycle_key);
ALTER TABLE billing_payments ADD COLUMN IF NOT EXISTS request_key VARCHAR(64) NULL, ADD COLUMN IF NOT EXISTS request_hash CHAR(64) NULL, ADD UNIQUE KEY uq_payment_request (request_key);
