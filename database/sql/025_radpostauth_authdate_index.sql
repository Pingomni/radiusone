-- Monitoring counts RADIUS accepts/rejects for the last 15 minutes and hour on every refresh;
-- without this index each count scans the whole post-auth log, which only grows.
ALTER TABLE radpostauth ADD INDEX IF NOT EXISTS idx_radpostauth_authdate (authdate);
