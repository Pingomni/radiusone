ALTER TABLE customer_profiles ADD COLUMN IF NOT EXISTS auth_method ENUM('pppoe','dhcp') NOT NULL DEFAULT 'pppoe' AFTER username;
