-- Compatibility for older deployments; never run from a request or repository.
ALTER TABLE service_metadata ADD COLUMN IF NOT EXISTS static_ip_pool TEXT NULL AFTER ip_pool;
CREATE TABLE IF NOT EXISTS static_ip_pools (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pool_key VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  ip_ranges TEXT NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_static_ip_pools_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE static_ip_pools
  ADD COLUMN IF NOT EXISTS price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER ip_ranges,
  ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER price;
