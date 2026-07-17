-- 008 — Real-world deliveries: short, rejected, and over-delivered.
--
-- Until now a DO line carried ONE number (qty_accepted), seeded straight from
-- what the OCR read. If the receiver lowered it because 3 were damaged, those 3
-- silently evaporated: no rejected quantity, no reason, nothing to chase.
--
-- Now each line records what ARRIVED (qty_delivered) and what was ACCEPTED
-- (qty_accepted). The difference is qty_rejected and needs a reason. Only the
-- accepted quantity is receipted against the PO, so rejected goods stay
-- outstanding and the supplier still owes them.
--
-- Over-delivery is recorded but held: the DO parks in 'exception' and the PO
-- line is flagged over_received until a PM/superadmin approves the overage.
--
-- All idempotent — the migrate runner treats "duplicate column" as benign.

-- What the paperwork said arrived. Backfilled below for existing rows.
ALTER TABLE do_lines ADD COLUMN qty_delivered NUMERIC;
-- Refused goods + why. reject_reason is one of the REASONS list in
-- App\Service\MatchingService; reject_note is the receiver's own words.
ALTER TABLE do_lines ADD COLUMN qty_rejected NUMERIC NOT NULL DEFAULT 0;
ALTER TABLE do_lines ADD COLUMN reject_reason TEXT;
ALTER TABLE do_lines ADD COLUMN reject_note TEXT;

-- Over-delivery sign-off (header level: one decision per delivery).
ALTER TABLE delivery_orders ADD COLUMN over_approved_by INTEGER REFERENCES users(id);
ALTER TABLE delivery_orders ADD COLUMN over_approved_at TEXT;
ALTER TABLE delivery_orders ADD COLUMN over_approval_note TEXT;

-- Existing confirmed lines: what was accepted is the best record we have of
-- what arrived, and nothing was ever rejected. Leaves history consistent
-- instead of showing "delivered: blank".
UPDATE do_lines SET qty_delivered = COALESCE(qty_accepted, ocr_qty) WHERE qty_delivered IS NULL;
UPDATE do_lines SET qty_rejected = 0 WHERE qty_rejected IS NULL;

CREATE INDEX IF NOT EXISTS idx_doline_rejected ON do_lines(reject_reason);
