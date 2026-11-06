-- RadiusOne Pro: customer contracts, and card payments on the public pay page. A contract keeps the text as it was
-- issued (body), so changing the template never changes a signed contract. A customer has at most one active contract.
CREATE TABLE IF NOT EXISTS customer_contracts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_number VARCHAR(40) NOT NULL UNIQUE,
  username VARCHAR(64) NOT NULL,
  service_group VARCHAR(64) NOT NULL,
  service_name VARCHAR(120) NOT NULL,
  monthly_price DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  billing_period ENUM('monthly','3_months','6_months','yearly') NOT NULL DEFAULT 'monthly',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  term_months SMALLINT UNSIGNED NOT NULL,
  status ENUM('active','ended','cancelled') NOT NULL DEFAULT 'active',
  active_flag TINYINT GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN 1 END) STORED,
  body MEDIUMTEXT NOT NULL,
  first_invoice_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_contract_active_customer (username, active_flag),
  INDEX idx_contract_customer (username),
  INDEX idx_contract_status_end (status, end_date),
  CONSTRAINT fk_contract_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON UPDATE CASCADE,
  CONSTRAINT fk_contract_invoice FOREIGN KEY (first_invoice_id) REFERENCES billing_invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_creator FOREIGN KEY (created_by) REFERENCES portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pay_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token CHAR(40) NOT NULL UNIQUE,
  username VARCHAR(64) NOT NULL,
  contract_id BIGINT UNSIGNED NOT NULL,
  billing_period ENUM('monthly','3_months','6_months','yearly') NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  provider ENUM('pok','stripe') NOT NULL DEFAULT 'pok',
  -- test: POK staging or a Stripe test key, no real money, never issues an invoice; live: real payments.
  mode ENUM('test','live') NOT NULL DEFAULT 'test',
  provider_order_id VARCHAR(191) NULL,
  checkout_url VARCHAR(2048) NULL,
  expires_at DATETIME NULL,
  status ENUM('pending','paid','canceled','expired','mismatch','failed','reviewed') NOT NULL DEFAULT 'pending',
  invoice_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  paid_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_pay_provider_order (provider, provider_order_id),
  INDEX idx_pay_customer (username),
  INDEX idx_pay_status (status, created_at),
  INDEX idx_pay_paid (paid_at),
  CONSTRAINT fk_pay_customer FOREIGN KEY (username) REFERENCES customer_profiles(username) ON UPDATE CASCADE,
  CONSTRAINT fk_pay_contract FOREIGN KEY (contract_id) REFERENCES customer_contracts(id),
  CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_reviewer FOREIGN KEY (reviewed_by) REFERENCES portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A customer the ISP keeps off the pay page (Clients → the customer → Online payment).
ALTER TABLE customer_profiles ADD COLUMN IF NOT EXISTS online_pay TINYINT(1) NOT NULL DEFAULT 1;
