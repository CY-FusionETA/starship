<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Auth;
use App\Perm;
use App\Support\Normalizer;
use App\Support\Filter;
use App\Service\Xero\XeroClientFactory;

final class PurchaseOrderRepo
{
    public static function find(int $id): ?array
    {
        return Db::one(
            "SELECT po.*, s.name AS supplier_name, p.name AS project_name, p.project_code, r.mr_number
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id
             JOIN projects p ON p.id = po.project_id
             LEFT JOIN requisitions r ON r.id = po.requisition_id
             WHERE po.id = ?",
            [$id]
        );
    }

    /**
     * Purchase orders, newest first, optionally filtered.
     * $f: q (PO no / supplier / project), status, supplier_id, project_id, xero, from, to (order_date).
     */
    public static function all(array $f = []): array
    {
        $clauses = [
            Filter::projectScope(Perm::projectIds(), 'po.project_id'),
            Filter::search($f['q'] ?? '', ['po.po_number', 's.name', 'p.project_code', 'p.name']),
            Filter::equals('po.status', $f['status'] ?? ''),
            Filter::equals('po.supplier_id', $f['supplier_id'] ?? ''),
            Filter::equals('po.project_id', $f['project_id'] ?? ''),
            Filter::dateFrom('po.order_date', $f['from'] ?? ''),
            Filter::dateTo('po.order_date', $f['to'] ?? ''),
        ];
        // Xero: synced = has an id from Xero; not-synced = never pushed.
        if (($f['xero'] ?? '') === 'synced')     $clauses[] = ["po.xero_po_id IS NOT NULL AND po.xero_po_id != ''", []];
        if (($f['xero'] ?? '') === 'not_synced') $clauses[] = ["(po.xero_po_id IS NULL OR po.xero_po_id = '')", []];

        [$where, $args] = Filter::build($clauses);
        return Db::all(
            "SELECT po.*, s.name AS supplier_name, p.project_code
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id
             JOIN projects p ON p.id = po.project_id
             {$where}
             ORDER BY po.created_at DESC",
            $args
        );
    }

    /** "WHERE project_id IN (…)" for the current user, or '' when unscoped. */
    private static function scope(string $col = 'project_id'): array
    {
        $c = Filter::projectScope(Perm::projectIds(), $col);
        return $c === null ? ['', []] : [' WHERE ' . $c[0], $c[1]];
    }

    /** Statuses actually present in what this user can see, for the filter dropdown. */
    public static function statuses(): array
    {
        [$w, $a] = self::scope();
        return array_column(Db::all("SELECT DISTINCT status FROM purchase_orders{$w} ORDER BY status", $a), 'status');
    }

    public static function count(): int
    {
        [$w, $a] = self::scope();
        return (int)Db::scalar("SELECT COUNT(*) FROM purchase_orders{$w}", $a);
    }

    public static function lines(int $poId): array
    {
        return Db::all(
            "SELECT pl.*, (pl.qty_ordered - pl.qty_received) AS balance_qty, c.item_code, c.xero_item_code FROM po_lines pl
             LEFT JOIN catalogue_items c ON c.id = pl.catalogue_item_id
             WHERE pl.purchase_order_id = ? ORDER BY pl.line_no",
            [$poId]
        );
    }

    public static function poNumberExists(string $poNumber): bool
    {
        return (bool)Db::one("SELECT id FROM purchase_orders WHERE po_number = ?", [trim($poNumber)]);
    }

    /** POs still open to receiving, for the DO link dropdown. */
    /** Open POs the current user may link a delivery to — scoped to their projects. */
    public static function openForSelect(): array
    {
        $c = Filter::projectScope(Perm::projectIds(), 'po.project_id');
        [$and, $args] = $c === null ? ['', []] : [' AND ' . $c[0], $c[1]];
        return Db::all(
            "SELECT po.id, po.po_number, po.status, s.name AS supplier_name, p.project_code
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id
             JOIN projects p ON p.id = po.project_id
             WHERE po.status IN ('issued','partially_received'){$and}
             ORDER BY po.created_at DESC",
            $args
        );
    }

    /** Open (not fully/over received) lines of a PO, with catalogue name. */
    public static function openLines(int $poId): array
    {
        return Db::all(
            "SELECT pl.*, (pl.qty_ordered - pl.qty_received) AS balance_qty, c.name AS catalogue_name, c.item_code
             FROM po_lines pl LEFT JOIN catalogue_items c ON c.id = pl.catalogue_item_id
             WHERE pl.purchase_order_id = ? AND pl.line_status NOT IN ('fully_received','closed')
             ORDER BY pl.line_no",
            [$poId]
        );
    }

    public static function lineById(int $id): ?array
    {
        return Db::one("SELECT * FROM po_lines WHERE id = ?", [$id]);
    }

    /** Fulfilment totals for a PO: ordered / received / outstanding across all lines. */
    public static function fulfilment(int $poId): array
    {
        $row = Db::one(
            "SELECT COALESCE(SUM(qty_ordered),0)                        AS ordered,
                    COALESCE(SUM(qty_received),0)                       AS received,
                    COALESCE(SUM(MAX(qty_ordered - qty_received, 0)),0) AS outstanding,
                    COUNT(*)                                            AS lines,
                    COALESCE(SUM(CASE WHEN qty_received + 1e-6 < qty_ordered THEN 1 ELSE 0 END),0) AS open_lines
             FROM po_lines WHERE purchase_order_id = ?",
            [$poId]
        ) ?: [];
        return [
            'ordered'     => (float)($row['ordered'] ?? 0),
            'received'    => (float)($row['received'] ?? 0),
            'outstanding' => (float)($row['outstanding'] ?? 0),
            'lines'       => (int)($row['lines'] ?? 0),
            'open_lines'  => (int)($row['open_lines'] ?? 0),
        ];
    }

    /** All delivery orders booked against this PO (newest first), with received totals. */
    public static function relatedDeliveryOrders(int $poId): array
    {
        return Db::all(
            "SELECT d.id, d.do_number, d.delivery_date, d.status, d.created_at,
                    (SELECT COALESCE(SUM(dl.qty_accepted),0) FROM do_lines dl
                       WHERE dl.delivery_order_id = d.id AND dl.is_confirmed = 1 AND dl.matched_po_line_id IS NOT NULL) AS qty_received,
                    (SELECT COUNT(*) FROM do_lines dl WHERE dl.delivery_order_id = d.id) AS line_count
             FROM delivery_orders d
             WHERE d.purchase_order_id = ?
             ORDER BY d.created_at DESC",
            [$poId]
        );
    }

    /** Invoices/bills booked against this PO. Empty until the billing flow lands. */
    public static function relatedBills(int $poId): array
    {
        return Db::all(
            "SELECT b.id, b.invoice_number, b.invoice_date, b.total_amount, b.status
             FROM bills b WHERE b.purchase_order_id = ?
             ORDER BY b.created_at DESC",
            [$poId]
        );
    }

    /** Recompute a PO's header status from its line statuses. */
    public static function recomputeStatus(int $poId): void
    {
        $lines = Db::all("SELECT line_status FROM po_lines WHERE purchase_order_id = ?", [$poId]);
        if (!$lines) return;
        $allDone = true; $anyRecv = false;
        foreach ($lines as $l) {
            if (!in_array($l['line_status'], ['fully_received', 'over_received', 'closed'], true)) $allDone = false;
            if ($l['line_status'] !== 'open') $anyRecv = true;
        }
        $cur = Db::scalar("SELECT status FROM purchase_orders WHERE id = ?", [$poId]);
        if (in_array($cur, ['closed', 'cancelled'], true)) return;
        $new = $allDone ? 'fully_received' : ($anyRecv ? 'partially_received' : 'issued');
        if ($new !== $cur) Db::update('purchase_orders', $poId, ['status' => $new]);
    }

    /**
     * Create one supplier PO from a subset of an MR's lines.
     * $selected: array of ['requisition_line_id'=>int, 'qty'=>float, 'unit_price'=>?float].
     */
    public static function createFromRequisition(int $reqId, int $supplierId, string $poNumber, ?string $orderDate, array $selected): int
    {
        $req = Db::one("SELECT * FROM requisitions WHERE id = ?", [$reqId]);
        if (!$req) throw new \RuntimeException('Requisition not found');

        $poId = Db::tx(function () use ($reqId, $req, $supplierId, $poNumber, $orderDate, $selected) {
            $poId = Db::insert('purchase_orders', [
                'po_number'      => trim($poNumber),
                'po_number_norm' => Normalizer::poNumber($poNumber),
                'requisition_id' => $reqId,
                'supplier_id'    => $supplierId,
                'project_id'     => (int)$req['project_id'],
                'order_date'     => $orderDate ?: null,
                'currency'       => 'MYR',
                'status'         => 'issued',
                'created_by'     => Auth::id(),
            ]);

            $no = 0; $total = 0.0;
            foreach ($selected as $sel) {
                $rl = Db::one("SELECT * FROM requisition_lines WHERE id = ? AND requisition_id = ?",
                    [(int)$sel['requisition_line_id'], $reqId]);
                if (!$rl) continue;
                $qty = (float)$sel['qty'];
                if ($qty <= 0) continue;
                $remaining = (float)$rl['qty'] - (float)$rl['qty_ordered'];
                if ($qty > $remaining) $qty = $remaining;   // never over-order a line
                if ($qty <= 0) continue;
                $price = ($sel['unit_price'] === '' || $sel['unit_price'] === null) ? null : (float)$sel['unit_price'];
                $no++;
                $lineTotal = $price !== null ? $qty * $price : null;
                if ($lineTotal !== null) $total += $lineTotal;

                Db::insert('po_lines', [
                    'purchase_order_id'   => $poId,
                    'line_no'             => $no,
                    'requisition_line_id' => (int)$rl['id'],
                    'catalogue_item_id'   => $rl['catalogue_item_id'] ?: null,
                    'description'         => $rl['raw_description'],
                    'qty_ordered'         => $qty,
                    'uom'                 => $rl['uom'],
                    'unit_price'          => $price,
                    'line_total'          => $lineTotal,
                    'qty_received'        => 0,
                    'line_status'         => 'open',
                ]);
                // roll qty_ordered up onto the MR line
                Db::q("UPDATE requisition_lines SET qty_ordered = qty_ordered + ? WHERE id = ?", [$qty, (int)$rl['id']]);
            }

            if ($no === 0) throw new \RuntimeException('No valid lines selected for this PO.');
            Db::update('purchase_orders', $poId, ['total_amount' => $total]);
            RequisitionRepo::recompute($reqId);
            AuditRepo::log('purchase_order', $poId, 'create', ['po_number' => $poNumber, 'lines' => $no]);
            return $poId;
        });

        // Auto-push to Xero. Never blocks PO creation — records status/error.
        self::syncToXero($poId);
        return $poId;
    }

    /** Edit PO header fields. Throws if the new PO number clashes with another PO. */
    public static function editHeader(int $id, string $poNumber, ?string $orderDate): void
    {
        $poNumber = trim($poNumber);
        if ($poNumber === '') throw new \RuntimeException('PO number is required.');
        $clash = Db::one("SELECT id FROM purchase_orders WHERE po_number = ? AND id <> ?", [$poNumber, $id]);
        if ($clash) throw new \RuntimeException('PO number "' . $poNumber . '" already exists.');
        Db::update('purchase_orders', $id, [
            'po_number'      => $poNumber,
            'po_number_norm' => Normalizer::poNumber($poNumber),
            'order_date'     => $orderDate ?: null,
        ]);
        AuditRepo::log('purchase_order', $id, 'edit', ['po_number' => $poNumber]);
    }

    /**
     * Delete a PO. Blocked when any delivery order has been received against it.
     * Otherwise the qty_ordered rolled onto the source requisition lines is
     * given back, the requisition status is recomputed, and po_lines cascade.
     */
    public static function delete(int $id): void
    {
        $po = self::find($id);
        if (!$po) throw new \RuntimeException('Purchase order not found.');

        $doRef  = Db::scalar("SELECT COUNT(*) FROM delivery_orders WHERE purchase_order_id = ?", [$id]);
        $lineRef = Db::scalar(
            "SELECT COUNT(*) FROM do_lines dl JOIN po_lines pl ON pl.id = dl.matched_po_line_id
             WHERE pl.purchase_order_id = ?",
            [$id]
        );
        if ((int)$doRef > 0 || (int)$lineRef > 0) {
            throw new \RuntimeException('Cannot delete: goods have been delivered against this PO. Remove the linked delivery order(s) first.');
        }

        Db::tx(function () use ($id, $po) {
            $reqIds = [];
            foreach (Db::all("SELECT requisition_line_id AS rl, qty_ordered AS q FROM po_lines WHERE purchase_order_id = ?", [$id]) as $pl) {
                $rlId = (int)($pl['rl'] ?? 0);
                if (!$rlId) continue;
                Db::q("UPDATE requisition_lines SET qty_ordered = MAX(0, qty_ordered - ?) WHERE id = ?", [(float)$pl['q'], $rlId]);
                $rid = Db::scalar("SELECT requisition_id FROM requisition_lines WHERE id = ?", [$rlId]);
                if ($rid) $reqIds[(int)$rid] = true;
            }
            Db::q("DELETE FROM purchase_orders WHERE id = ?", [$id]);
            foreach (array_keys($reqIds) as $rid) RequisitionRepo::recompute($rid);
            AuditRepo::log('purchase_order', $id, 'delete', ['po_number' => $po['po_number']]);
        });
    }

    /**
     * Create the matching Purchase Order in Xero. Idempotent-ish: if the PO is
     * already synced it returns early. Records xero_po_id + xero_synced_at on
     * success, xero_last_error on failure. Falls back to the stub (no-op) when
     * Xero isn't enabled/connected. Returns the client result array.
     */
    public static function syncToXero(int $poId): array
    {
        $po = self::find($poId);
        if (!$po) return ['xero_po_id' => null, 'stubbed' => true];
        if (!empty($po['xero_po_id'])) {
            return ['xero_po_id' => $po['xero_po_id'], 'stubbed' => false, 'already' => true];
        }
        $res = XeroClientFactory::make()->createPurchaseOrder($po, self::lines($poId));
        if (!empty($res['xero_po_id'])) {
            Db::update('purchase_orders', $poId, [
                'xero_po_id'      => $res['xero_po_id'],
                'xero_synced_at'  => date('Y-m-d H:i:s'),
                'xero_last_error' => null,
            ]);
        } elseif (empty($res['stubbed']) && !empty($res['error'])) {
            Db::update('purchase_orders', $poId, ['xero_last_error' => $res['error']]);
        }
        return $res;
    }
}
