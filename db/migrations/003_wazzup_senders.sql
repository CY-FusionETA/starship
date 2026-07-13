-- 003 — Wazzup WhatsApp hotline: allowlist of sender numbers.
CREATE TABLE IF NOT EXISTS wa_senders (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  phone_e164   TEXT NOT NULL UNIQUE,
  name         TEXT,
  is_active    INTEGER NOT NULL DEFAULT 1,
  last_seen_at TEXT,
  created_at   TEXT DEFAULT CURRENT_TIMESTAMP
);
