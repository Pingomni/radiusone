-- Preflight duplicates first. This deliberately fails on collisions and never reassigns customers.
ALTER TABLE customer_profiles
 ADD COLUMN allocated_static_ip VARCHAR(45) GENERATED ALWAYS AS (NULLIF(framed_ip_address, '')) STORED,
 ADD UNIQUE KEY uq_customer_static_ip (allocated_static_ip);
