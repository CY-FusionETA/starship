-- 002 — Xero auto-sync support.
-- app_settings holds UI-editable runtime config (Xero client_id/secret/enabled/redirect).
-- purchase_orders gains sync bookkeeping columns. All idempotent — the migrate
-- runner treats "duplicate column"/"already exists" as benign.

CREATE TABLE IF NOT EXISTS app_settings (
  key        TEXT PRIMARY KEY,
  value      TEXT,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE purchase_orders ADD COLUMN xero_last_error TEXT;
ALTER TABLE purchase_orders ADD COLUMN xero_synced_at TEXT;
