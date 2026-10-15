-- Interface language per operator (en, sq). NULL uses APP_LOCALE, else English.
ALTER TABLE portal_users ADD COLUMN IF NOT EXISTS language VARCHAR(8) NULL;
