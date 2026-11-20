-- How many ONUs the portal adds on one PON port of an OLT (SmartOLT's "max ONUs per PON"). The portal's own limit:
-- nothing changes on the OLT, and ONUs already on a port stay.
ALTER TABLE olts
  ADD COLUMN IF NOT EXISTS max_onus_per_pon SMALLINT UNSIGNED NOT NULL DEFAULT 128 AFTER rx_critical;
