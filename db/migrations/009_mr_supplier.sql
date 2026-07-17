-- Optional supplier chosen on the Material Requisition. On PM approval the
-- draft Purchase Order is raised to this supplier; if left blank it falls back
-- to a "To Be Confirmed" placeholder supplier that procurement fixes in Xero.
ALTER TABLE requisitions ADD COLUMN supplier_id INTEGER REFERENCES suppliers(id);
