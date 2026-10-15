-- Proposal: apply only after reviewing existing foreign-key names and taking a verified backup.
-- Does not delete or rewrite any historical financial/accounting/document data.
ALTER TABLE customer_profiles ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER account_status;
ALTER TABLE billing_invoices
  DROP FOREIGN KEY fk_billing_invoice_customer;
ALTER TABLE billing_invoices
  ADD CONSTRAINT fk_billing_invoice_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT;
ALTER TABLE billing_payments
  DROP FOREIGN KEY fk_billing_payment_invoice,
  DROP FOREIGN KEY fk_billing_payment_customer;
ALTER TABLE billing_payments
  ADD CONSTRAINT fk_billing_payment_invoice FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_billing_payment_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT;
ALTER TABLE uploaded_documents
  DROP FOREIGN KEY fk_documents_customer;
ALTER TABLE uploaded_documents
  ADD CONSTRAINT fk_documents_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT;
ALTER TABLE notification_logs
  DROP FOREIGN KEY fk_notification_customer;
ALTER TABLE notification_logs
  ADD CONSTRAINT fk_notification_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT;
