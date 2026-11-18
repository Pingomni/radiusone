-- Changing an OLT from the portal: off for every OLT until someone turns it on for that OLT.
-- provision_defaults is what the last ONU was added with, to fill the form in for the next one.
-- olt_onus.active is the OLT's own switch for an ONU (1 on, 0 turned off by an operator), as read over SNMP.
ALTER TABLE olts
  ADD COLUMN IF NOT EXISTS manage_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER telnet_password,
  ADD COLUMN IF NOT EXISTS provision_defaults TEXT NULL AFTER manage_enabled;

ALTER TABLE olt_onus
  ADD COLUMN IF NOT EXISTS active TINYINT(1) NULL AFTER status;
