-- Disconnect method per NAS: RADIUS CoA (shared secret) by default, or the RouterOS API when an
-- operator switches it on under NAS → API. MikroTik routers that already had an API login keep
-- using the API, so nothing changes for them until someone turns it off.
ALTER TABLE nas_metadata ADD COLUMN IF NOT EXISTS api_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER api_password;
UPDATE nas_metadata SET api_enabled = 1 WHERE COALESCE(api_user, '') <> '' AND COALESCE(api_password, '') <> '';
