-- Historical source/anchor cannot be inferred from notes, dates or the current plan.
ALTER TABLE billing_invoices
  ADD COLUMN cycle_class ENUM('manual','generated') NULL,
  ADD COLUMN billing_anchor_day TINYINT UNSIGNED NULL,
  ADD COLUMN replaces_invoice_id BIGINT UNSIGNED NULL,
  ADD UNIQUE KEY uq_invoice_replacement (replaces_invoice_id),
  ADD CONSTRAINT fk_invoice_replacement FOREIGN KEY (replaces_invoice_id) REFERENCES billing_invoices(id) ON DELETE RESTRICT,
  ADD COLUMN active_cycle_key VARCHAR(190) GENERATED ALWAYS AS (CASE WHEN status <> 'void' THEN cycle_key ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_billing_active_cycle (active_cycle_key),
  ADD INDEX idx_billing_cycle_history (cycle_key),
  DROP INDEX uq_billing_cycle;
