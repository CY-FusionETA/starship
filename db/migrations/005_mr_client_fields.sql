-- 005 — Extra MR header fields from the client's paper form GE(S)-PU-F01/1.
-- Existing columns already cover: mr_number, project (project_id → code + title),
-- request_date (Submission Date), delivery_date (Specific Delivery Date),
-- requested_by. These add the fields the system didn't have.
-- All idempotent — the migrate runner treats "duplicate column" as benign.

-- Urgency: ASAP/URGENT · ASAP - Partial Delivery Accepted · Specify Date Below · TBA
ALTER TABLE requisitions ADD COLUMN urgency TEXT;
-- Requester contact details.
ALTER TABLE requisitions ADD COLUMN requester_mobile TEXT;
ALTER TABLE requisitions ADD COLUMN requester_email TEXT;
