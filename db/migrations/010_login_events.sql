-- 010 — Sign-in audit log: who signed in, from where, on what device.
-- login_events records every attempt (success and failed).
-- ip_geo caches the geo lookup per IP so we don't re-hit the provider.
-- NOTE: keep comments on their own lines — the migrate runner splits on ';'.

CREATE TABLE IF NOT EXISTS login_events (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER,
  email       TEXT,
  success     INTEGER NOT NULL DEFAULT 1,
  ip          TEXT,
  user_agent  TEXT,
  os          TEXT,
  browser     TEXT,
  device_type TEXT,
  country     TEXT,
  city        TEXT,
  isp         TEXT,
  created_at  TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_user ON login_events(user_id);
CREATE INDEX IF NOT EXISTS idx_login_created ON login_events(created_at);
CREATE INDEX IF NOT EXISTS idx_login_ip ON login_events(ip);

CREATE TABLE IF NOT EXISTS ip_geo (
  ip          TEXT PRIMARY KEY,
  country     TEXT,
  region      TEXT,
  city        TEXT,
  isp         TEXT,
  resolved_at TEXT DEFAULT CURRENT_TIMESTAMP
);
