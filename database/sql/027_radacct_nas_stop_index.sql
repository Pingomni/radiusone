-- Monitoring shows when each NAS last sent accounting. The latest stop time per NAS is one index
-- lookup with this index; without it every refresh read all of that NAS's sessions.
ALTER TABLE radacct ADD INDEX IF NOT EXISTS idx_radacct_nas_stop (nasipaddress, acctstoptime);
