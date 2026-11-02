-- Settings → Firewall: the rules of the nftables table RadiusOne manages (inet radiusone), in order. Off until an
-- administrator turns it on; the snapshot in force lives in settings (firewall_active), read by root's helper.
-- source: empty (anyone), an address or network, or "routers" (the addresses in the nas table).
CREATE TABLE IF NOT EXISTS firewall_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  action VARCHAR(8) NOT NULL,
  protocol VARCHAR(8) NOT NULL,
  source VARCHAR(49) NOT NULL DEFAULT '',
  ports VARCHAR(255) NOT NULL DEFAULT '',
  comment VARCHAR(255) NOT NULL DEFAULT '',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  KEY idx_firewall_rules_position (position, id)
) ENGINE=InnoDB;
