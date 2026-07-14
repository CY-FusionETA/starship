-- 004 — Xero as the master data source.
-- Starship's suppliers / projects / catalogue mirror Xero's Contacts / Project
-- tracking category / Items. These columns hold the durable Xero link (the guid
-- ids) plus a last-synced timestamp so the UI can show what came from Xero.
-- All idempotent — the migrate runner treats "duplicate column" as benign.

-- Catalogue item ⇄ Xero Item. xero_item_code already exists (the Xero Code we
-- send); xero_item_id stores the immutable Xero ItemID guid.
ALTER TABLE catalogue_items ADD COLUMN xero_item_id TEXT;
ALTER TABLE catalogue_items ADD COLUMN xero_synced_at TEXT;
ALTER TABLE catalogue_items ADD COLUMN xero_last_error TEXT;

-- Project ⇄ a tracking option inside Xero's chosen "Project" tracking category.
-- xero_tracking_option already exists (the option name, used on line items);
-- these store the guids so we can push line-level Tracking and re-match on sync.
ALTER TABLE projects ADD COLUMN xero_tracking_option_id TEXT;
ALTER TABLE projects ADD COLUMN xero_tracking_category_id TEXT;
ALTER TABLE projects ADD COLUMN xero_synced_at TEXT;

-- Supplier ⇄ Xero Contact. xero_contact_id already exists.
ALTER TABLE suppliers ADD COLUMN xero_synced_at TEXT;
