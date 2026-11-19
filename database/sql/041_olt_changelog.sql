-- Everything that changed on an OLT, as its changelog: what was done from the portal, and what the portal found changed
-- on the OLT itself (an ONU added, removed, moved or renamed there, its configuration different from the day before).
-- detail is JSON. audit_id is the audit log row a change was copied from when this table was made, so it is copied once.
CREATE TABLE IF NOT EXISTS olt_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  olt_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  actor VARCHAR(80) NOT NULL DEFAULT '',
  source VARCHAR(12) NOT NULL DEFAULT 'portal',
  action VARCHAR(40) NOT NULL,
  sn VARCHAR(32) NULL,
  position VARCHAR(32) NULL,
  detail MEDIUMTEXT NULL,
  audit_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_olt_changes_audit (audit_id),
  KEY idx_olt_changes_olt (olt_id, created_at),
  KEY idx_olt_changes_sn (olt_id, sn, created_at),
  CONSTRAINT fk_olt_changes_olt FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the audit log already has: changes to ONUs, found by the OLT's name they carry,
INSERT IGNORE INTO olt_changes (olt_id, created_at, actor, source, action, sn, position, detail, audit_id)
SELECT t.id, a.created_at, a.actor_username, 'portal', a.action, a.entity_id, JSON_UNQUOTE(JSON_EXTRACT(a.after_json, '$.position')), a.after_json, a.id
FROM audit_logs a JOIN olts t ON t.name = JSON_UNQUOTE(JSON_EXTRACT(a.after_json, '$.olt'))
WHERE a.entity_type = 'onu';

-- changes to the OLT itself (reads such as the display command line and downloads are not changes),
INSERT IGNORE INTO olt_changes (olt_id, created_at, actor, source, action, sn, position, detail, audit_id)
SELECT t.id, a.created_at, a.actor_username, IF(a.action IN ('olt_terminal', 'olt_console'), 'console', 'portal'), a.action, NULL,
  JSON_UNQUOTE(JSON_EXTRACT(a.after_json, '$.port')), a.after_json, a.id
FROM audit_logs a JOIN olts t ON CAST(t.id AS CHAR) = a.entity_id
WHERE a.entity_type = 'olt' AND a.action NOT IN ('cli', 'backup_download', 'delete', 'olt_console')
  AND NOT (a.action = 'olt_terminal' AND JSON_UNQUOTE(JSON_EXTRACT(a.after_json, '$.commands[0]')) REGEXP '^(display|scroll|undo smart|quit|enable|config|interface|return)( |$)');

-- and clients linked to ONUs, found by the ONU's serial number.
INSERT IGNORE INTO olt_changes (olt_id, created_at, actor, source, action, sn, position, detail, audit_id)
SELECT o.olt_id, a.created_at, a.actor_username, 'portal', a.action, o.sn, CONCAT(o.frame, '/', o.slot, '/', o.port, ':', o.ont_id),
  JSON_SET(COALESCE(a.after_json, a.before_json, '{}'), '$.client', a.entity_id), a.id
FROM audit_logs a JOIN olt_onus o ON o.sn = JSON_UNQUOTE(JSON_EXTRACT(COALESCE(a.after_json, a.before_json), '$.sn'))
WHERE a.entity_type = 'client' AND a.action IN ('link_onu', 'unlink_onu');
