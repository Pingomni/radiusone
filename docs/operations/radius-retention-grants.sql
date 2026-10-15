-- Portal grants for Settings → Data retention (artisan frweb:prune-radius, scheduled daily). Apply as a
-- MariaDB administrator. Only DELETE is added; the column-limited SELECT on radpostauth stays as it is
-- (the delete filters on authdate, which the portal can already read).
GRANT DELETE ON radius.radacct TO 'frweb_portal'@'localhost';
GRANT DELETE ON radius.radpostauth TO 'frweb_portal'@'localhost';
