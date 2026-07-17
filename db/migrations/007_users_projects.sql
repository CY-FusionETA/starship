-- 007 — Per-user project access + the five real roles.
--
-- Until now every logged-in user could see every project's MRs, POs and DOs.
-- user_projects is the allow-list: a user sees a record only when its project
-- is in their list. Superadmin and finance are unscoped (see everything) — that
-- is decided in code (src/Perm.php), not here.
--
-- Roles: admin (superadmin) · pm · procurement · requester · finance
-- All idempotent — the migrate runner treats "already exists" as benign.

CREATE TABLE IF NOT EXISTS user_projects (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, project_id)
);
CREATE INDEX IF NOT EXISTS idx_userproj_user ON user_projects(user_id);
CREATE INDEX IF NOT EXISTS idx_userproj_project ON user_projects(project_id);

-- Who created the account, for the Settings > Users list.
ALTER TABLE users ADD COLUMN created_by INTEGER REFERENCES users(id);

-- Legacy roles → the new set. 'staff' did procurement work (raise POs, capture
-- DOs); 'purchaser'/'ap' were accepted by route guards but never created.
UPDATE users SET role = 'procurement' WHERE role IN ('staff', 'purchaser');
UPDATE users SET role = 'finance'     WHERE role = 'ap';

-- Existing accounts could already see every project, so grant them the projects
-- that exist today — this migration shouldn't take access away from anyone
-- mid-flight. Accounts created from here on start with nothing until the
-- superadmin assigns them, and no one is auto-granted future projects.
INSERT OR IGNORE INTO user_projects (user_id, project_id)
SELECT u.id, p.id FROM users u CROSS JOIN projects p
WHERE u.role NOT IN ('admin', 'finance');
