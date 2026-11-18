-- From which signal an ONU counts as weak, and as very weak, per OLT. The portal's own setting: nothing on the OLT.
ALTER TABLE olts
  ADD COLUMN IF NOT EXISTS rx_warning DECIMAL(5,1) NOT NULL DEFAULT -27.0 AFTER provision_defaults,
  ADD COLUMN IF NOT EXISTS rx_critical DECIMAL(5,1) NOT NULL DEFAULT -30.0 AFTER rx_warning;
