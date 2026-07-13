-- Starship — SQLite schema (single-file DB in storage/).
-- No generated columns (server SQLite 3.26): *_norm columns are set in code,
-- balance_qty is computed in queries.

-- 1. users
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,
  email         TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'purchaser',
  is_active     INTEGER NOT NULL DEFAULT 1,
  last_login_at TEXT,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at    TEXT DEFAULT CURRENT_TIMESTAMP
);

-- 2. suppliers
CREATE TABLE IF NOT EXISTS suppliers (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  name           TEXT NOT NULL,
  short_code     TEXT,
  phone          TEXT,
  whatsapp_e164  TEXT,
  email          TEXT,
  sst_reg_no     TEXT,
  myinvois_tin   TEXT,
  xero_contact_id TEXT,
  po_number_hint TEXT,
  is_active      INTEGER NOT NULL DEFAULT 1,
  created_at     TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at     TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_suppliers_wa ON suppliers(whatsapp_e164);
CREATE INDEX IF NOT EXISTS idx_suppliers_name ON suppliers(name);

-- 3. projects
CREATE TABLE IF NOT EXISTS projects (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  project_code      TEXT NOT NULL UNIQUE,
  project_code_norm TEXT,
  name              TEXT NOT NULL,
  site_address      TEXT,
  xero_tracking_option TEXT,
  is_active         INTEGER NOT NULL DEFAULT 1,
  created_at        TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at        TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_projects_code_norm ON projects(project_code_norm);
CREATE INDEX IF NOT EXISTS idx_projects_name ON projects(name);

-- 4. catalogue_items
CREATE TABLE IF NOT EXISTS catalogue_items (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  item_code     TEXT NOT NULL UNIQUE,
  name          TEXT NOT NULL,
  brand         TEXT,
  model         TEXT,
  description   TEXT,
  uom           TEXT,
  xero_item_code TEXT,
  category      TEXT,
  unit_price    NUMERIC,
  search_blob   TEXT,
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at    TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_catalogue_brand ON catalogue_items(brand);
CREATE INDEX IF NOT EXISTS idx_catalogue_model ON catalogue_items(model);

-- 5. item_supplier_aliases (self-learning)
CREATE TABLE IF NOT EXISTS item_supplier_aliases (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  catalogue_item_id  INTEGER NOT NULL REFERENCES catalogue_items(id) ON DELETE CASCADE,
  supplier_id        INTEGER NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
  supplier_part_code TEXT,
  supplier_desc      TEXT NOT NULL,
  supplier_uom       TEXT,
  desc_norm          TEXT,
  confidence_default INTEGER DEFAULT 100,
  times_confirmed    INTEGER DEFAULT 0,
  created_at         TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at         TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_alias_supplier_partcode ON item_supplier_aliases(supplier_id, supplier_part_code);
CREATE INDEX IF NOT EXISTS idx_alias_supplier ON item_supplier_aliases(supplier_id);

-- 6. requisitions + lines
CREATE TABLE IF NOT EXISTS requisitions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  mr_number     TEXT NOT NULL UNIQUE,
  project_id    INTEGER NOT NULL REFERENCES projects(id),
  requested_by  TEXT,
  request_date  TEXT,
  delivery_date TEXT,
  status        TEXT NOT NULL DEFAULT 'draft',
  notes         TEXT,
  created_by    INTEGER REFERENCES users(id),
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at    TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_req_status ON requisitions(status);

CREATE TABLE IF NOT EXISTS requisition_lines (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  requisition_id    INTEGER NOT NULL REFERENCES requisitions(id) ON DELETE CASCADE,
  line_no           INTEGER NOT NULL,
  catalogue_item_id INTEGER REFERENCES catalogue_items(id),
  raw_description   TEXT NOT NULL,
  model_type        TEXT,
  qty               NUMERIC NOT NULL,
  uom               TEXT,
  qty_ordered       NUMERIC NOT NULL DEFAULT 0,
  status            TEXT NOT NULL DEFAULT 'open',
  remarks           TEXT,
  created_at        TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at        TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (requisition_id, line_no)
);
CREATE INDEX IF NOT EXISTS idx_reqline_item ON requisition_lines(catalogue_item_id);

-- 7. purchase_orders + po_lines
CREATE TABLE IF NOT EXISTS purchase_orders (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  po_number      TEXT NOT NULL UNIQUE,
  po_number_norm TEXT,
  requisition_id INTEGER REFERENCES requisitions(id),
  supplier_id    INTEGER NOT NULL REFERENCES suppliers(id),
  project_id     INTEGER NOT NULL REFERENCES projects(id),
  order_date     TEXT,
  currency       TEXT NOT NULL DEFAULT 'MYR',
  sst_amount     NUMERIC,
  total_amount   NUMERIC,
  status         TEXT NOT NULL DEFAULT 'draft',
  xero_po_id     TEXT,
  xero_last_error TEXT,
  xero_synced_at TEXT,
  created_by     INTEGER,
  created_at     TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at     TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_po_norm ON purchase_orders(po_number_norm);
CREATE INDEX IF NOT EXISTS idx_po_project ON purchase_orders(project_id);
CREATE INDEX IF NOT EXISTS idx_po_supplier ON purchase_orders(supplier_id);
CREATE INDEX IF NOT EXISTS idx_po_status ON purchase_orders(status);

CREATE TABLE IF NOT EXISTS po_lines (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  purchase_order_id    INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
  line_no              INTEGER NOT NULL,
  requisition_line_id  INTEGER REFERENCES requisition_lines(id),
  catalogue_item_id    INTEGER REFERENCES catalogue_items(id),
  description          TEXT NOT NULL,
  qty_ordered          NUMERIC NOT NULL,
  uom                  TEXT,
  unit_price           NUMERIC,
  discount_amount      NUMERIC,
  discount_pct         NUMERIC,
  line_total           NUMERIC,
  qty_received         NUMERIC NOT NULL DEFAULT 0,
  line_status          TEXT NOT NULL DEFAULT 'open',
  created_at           TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at           TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (purchase_order_id, line_no)
);
CREATE INDEX IF NOT EXISTS idx_poline_item ON po_lines(catalogue_item_id);
CREATE INDEX IF NOT EXISTS idx_poline_status ON po_lines(line_status);

-- 8. delivery_orders + do_lines
CREATE TABLE IF NOT EXISTS delivery_orders (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  do_number         TEXT,
  supplier_id       INTEGER REFERENCES suppliers(id),
  purchase_order_id INTEGER REFERENCES purchase_orders(id),
  project_id        INTEGER REFERENCES projects(id),
  po_reference_raw  TEXT,
  project_code_raw  TEXT,
  delivery_date     TEXT,
  image_path        TEXT NOT NULL,
  source_channel    TEXT NOT NULL DEFAULT 'manual_upload',
  wazzup_message_id TEXT,
  sender_wa_e164    TEXT,
  signature_present INTEGER,
  handwritten_notes TEXT,
  ocr_model         TEXT,
  ocr_confidence    NUMERIC,
  ocr_raw_json      TEXT,
  status            TEXT NOT NULL DEFAULT 'received',
  match_summary     TEXT,
  reviewed_by       INTEGER,
  reviewed_at       TEXT,
  created_at        TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at        TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_do_status ON delivery_orders(status);
CREATE INDEX IF NOT EXISTS idx_do_number ON delivery_orders(do_number);
CREATE INDEX IF NOT EXISTS idx_do_po ON delivery_orders(purchase_order_id);

CREATE TABLE IF NOT EXISTS do_lines (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  delivery_order_id INTEGER NOT NULL REFERENCES delivery_orders(id) ON DELETE CASCADE,
  line_no           INTEGER NOT NULL,
  ocr_description   TEXT NOT NULL,
  ocr_supplier_code TEXT,
  ocr_qty           NUMERIC,
  ocr_uom           TEXT,
  matched_po_line_id       INTEGER REFERENCES po_lines(id),
  matched_catalogue_item_id INTEGER REFERENCES catalogue_items(id),
  match_method      TEXT,
  match_score       NUMERIC,
  qty_accepted      NUMERIC,
  verdict           TEXT,
  is_confirmed      INTEGER NOT NULL DEFAULT 0,
  created_at        TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at        TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (delivery_order_id, line_no)
);
CREATE INDEX IF NOT EXISTS idx_doline_poline ON do_lines(matched_po_line_id);
CREATE INDEX IF NOT EXISTS idx_doline_verdict ON do_lines(verdict);

-- 9. bills
CREATE TABLE IF NOT EXISTS bills (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  supplier_id       INTEGER NOT NULL REFERENCES suppliers(id),
  purchase_order_id INTEGER REFERENCES purchase_orders(id),
  project_id        INTEGER REFERENCES projects(id),
  invoice_number    TEXT,
  myinvois_uin      TEXT,
  invoice_date      TEXT,
  currency          TEXT NOT NULL DEFAULT 'MYR',
  subtotal          NUMERIC,
  sst_amount        NUMERIC,
  total_amount      NUMERIC,
  status            TEXT NOT NULL DEFAULT 'draft',
  xero_bill_id      TEXT,
  xero_last_error   TEXT,
  attachment_path   TEXT,
  created_by        INTEGER,
  created_at        TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at        TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_bill_status ON bills(status);

-- 10. oauth_tokens (Xero)
CREATE TABLE IF NOT EXISTS oauth_tokens (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  provider       TEXT NOT NULL DEFAULT 'xero',
  tenant_id      TEXT,
  tenant_name    TEXT,
  access_token   TEXT NOT NULL,
  refresh_token  TEXT NOT NULL,
  expires_at     TEXT NOT NULL,
  scope          TEXT,
  updated_at     TEXT DEFAULT CURRENT_TIMESTAMP,
  created_at     TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (provider, tenant_id)
);

-- 11. webhook_events
CREATE TABLE IF NOT EXISTS webhook_events (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  provider       TEXT NOT NULL,
  external_id    TEXT,
  signature_ok   INTEGER,
  payload_json   TEXT NOT NULL,
  status         TEXT NOT NULL DEFAULT 'received',
  error_text     TEXT,
  delivery_order_id INTEGER,
  attempts       INTEGER NOT NULL DEFAULT 0,
  created_at     TEXT DEFAULT CURRENT_TIMESTAMP,
  processed_at   TEXT,
  UNIQUE (provider, external_id)
);
CREATE INDEX IF NOT EXISTS idx_webhook_status ON webhook_events(status);

-- 12. audit_log
CREATE TABLE IF NOT EXISTS audit_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER,
  entity_type TEXT NOT NULL,
  entity_id   INTEGER NOT NULL,
  action      TEXT NOT NULL,
  detail_json TEXT,
  created_at  TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log(user_id);

-- 12b. app_settings — key/value store for runtime config editable in the UI
-- (e.g. Xero client_id/secret/enabled). Overrides config.php via App\Settings.
CREATE TABLE IF NOT EXISTS app_settings (
  key        TEXT PRIMARY KEY,
  value      TEXT,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- 12c. wa_senders — WhatsApp numbers allowed to submit DOs/invoices via the
-- Wazzup hotline (managed in the superadmin Settings tab).
CREATE TABLE IF NOT EXISTS wa_senders (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  phone_e164   TEXT NOT NULL UNIQUE,
  name         TEXT,
  is_active    INTEGER NOT NULL DEFAULT 1,
  last_seen_at TEXT,
  created_at   TEXT DEFAULT CURRENT_TIMESTAMP
);

-- 13. gemini_ocr_runs
CREATE TABLE IF NOT EXISTS gemini_ocr_runs (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  delivery_order_id INTEGER NOT NULL REFERENCES delivery_orders(id) ON DELETE CASCADE,
  model             TEXT NOT NULL,
  attempt           INTEGER NOT NULL DEFAULT 1,
  confidence        NUMERIC,
  latency_ms        INTEGER,
  prompt_tokens     INTEGER,
  output_tokens     INTEGER,
  escalated         INTEGER NOT NULL DEFAULT 0,
  raw_response      TEXT,
  created_at        TEXT DEFAULT CURRENT_TIMESTAMP
);
