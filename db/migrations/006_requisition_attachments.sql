-- 006 — Quotation attachments on a Material Requisition, plus original filenames
-- on DO uploads (storage names are random hex, so the DB has to remember what
-- the user actually uploaded in order to show "quote-victaulic.pdf" back to them).
-- All idempotent — the migrate runner treats "already exists" as benign.

CREATE TABLE IF NOT EXISTS requisition_attachments (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  requisition_id    INTEGER NOT NULL REFERENCES requisitions(id) ON DELETE CASCADE,
  kind              TEXT NOT NULL DEFAULT 'quotation',
  file_path         TEXT NOT NULL,
  original_filename TEXT NOT NULL,
  mime_type         TEXT,
  size_bytes        INTEGER,
  uploaded_by       INTEGER REFERENCES users(id),
  created_at        TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_reqatt_req ON requisition_attachments(requisition_id);

-- Filename of the signed DO as uploaded (NULL for rows captured before this).
ALTER TABLE delivery_orders ADD COLUMN original_filename TEXT;
