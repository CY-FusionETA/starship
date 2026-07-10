<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Auth;
use App\Support\Normalizer;
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

    public static function all(): array
    {
        return Db::all(
            "SELECT po.*, s.name AS supplier_name, p.project_code
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id
             JOIN projects p ON p.id = po.project_id
             ORDER BY po.created_at DESC"
        );
    }

    public static function lines(int $poId): array
    {
        return Db::all(
            "SELECT pl.*, (pl.qty_ordered - pl.qty_received) AS balance_qty, c.item_code FROM po_lines pl
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
    public static function openForSelect(): array
    {
        return Db::all(
            "SELECT po.id, po.po_number, po.status, s.name AS supplier_name, p.project_code
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id
             JOIN projects p ON p.id = po.project_id
             WHERE po.status IN ('issued','partially_received')
             ORDER BY po.created_at DESC"
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

        // Xero seam (stubbed until Phase 6): logs intent, returns null id.
        $po = self::find($poId);
        $res = XeroClientFactory::make()->createPurchaseOrder($po, self::lines($poId));
        if (!empty($res['xero_po_id'])) {
            Db::update('purchase_orders', $poId, ['xero_po_id' => $res['xero_po_id']]);
        }
        return $poId;
    }
}
