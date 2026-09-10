-- Runtime settings, editable from the admin panel.
-- Existing installs: mysql -u USER -p DBNAME < migrations/001_settings.sql
-- Fresh installs get this from schema.sql already.

CREATE TABLE IF NOT EXISTS settings (
    name       VARCHAR(64) PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
