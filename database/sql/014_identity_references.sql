-- Review actual constraint names, preflight identity collisions, and pause writers before deployment.
ALTER TABLE billing_invoices DROP FOREIGN KEY fk_billing_invoice_customer;
ALTER TABLE billing_invoices ADD CONSTRAINT fk_billing_invoice_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE billing_payments DROP FOREIGN KEY fk_billing_payment_customer;
ALTER TABLE billing_payments ADD CONSTRAINT fk_billing_payment_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE uploaded_documents DROP FOREIGN KEY fk_documents_customer;
ALTER TABLE uploaded_documents ADD CONSTRAINT fk_documents_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE notification_logs DROP FOREIGN KEY fk_notification_customer;
ALTER TABLE notification_logs ADD CONSTRAINT fk_notification_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE TABLE customer_identity_history (
 username VARCHAR(64) NOT NULL PRIMARY KEY,
 customer_id BIGINT UNSIGNED NOT NULL,
 renamed_at DATETIME NOT NULL,
 CONSTRAINT fk_identity_customer FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE RESTRICT,
 INDEX idx_identity_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE billing_invoices ADD COLUMN IF NOT EXISTS identity_at_issue VARCHAR(64) NULL;
UPDATE billing_invoices SET identity_at_issue=username WHERE identity_at_issue IS NULL;
