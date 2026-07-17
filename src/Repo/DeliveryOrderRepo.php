<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Perm;
use App\Support\Filter;

final class DeliveryOrderRepo
{
    public static function find(int $id): ?array
    {
        return Db::one(
            "SELECT d.*, s.name AS supplier_name, p.project_code, po.po_number,
                    po.project_id AS po_project_id
             FROM delivery_orders d
             LEFT JOIN suppliers s ON s.id = d.supplier_id
             LEFT JOIN projects p ON p.id = d.project_id
             LEFT JOIN purchase_orders po ON po.id = d.purchase_order_id
             WHERE d.id = ?",
            [$id]
        );
    }

    /**
     * Delivery orders, newest first, optionally filtered.
     * $f: q (DO no / supplier / PO / raw refs / filename), status, supplier_id, source, from, to (delivery_date).
     */
    public static function all(array $f = []): array
    {
        [$where, $args] = Filter::build([
            // A DO that hasn't been matched yet has no project of its own, so fall
            // back to its PO's project. Both null = not yet triaged; those are
            // handled by unmatchedVisible() below rather than hidden from everyone.
            self::scopeClause(),
            Filter::search($f['q'] ?? '', [
                'd.do_number', 's.name', 'po.po_number', 'd.po_reference_raw',
                'd.project_code_raw', 'd.original_filename',
            ]),
            Filter::equals('d.status', $f['status'] ?? ''),
            Filter::equals('d.supplier_id', $f['supplier_id'] ?? ''),
            Filter::equals('d.source_channel', $f['source'] ?? ''),
            Filter::dateFrom('d.delivery_date', $f['from'] ?? ''),
            Filter::dateTo('d.delivery_date', $f['to'] ?? ''),
        ]);
        return Db::all(
            "SELECT d.*, s.name AS supplier_name, po.po_number
             FROM delivery_orders d
             LEFT JOIN suppliers s ON s.id = d.supplier_id
             LEFT JOIN purchase_orders po ON po.id = d.purchase_order_id
             {$where}
             ORDER BY d.created_at DESC",
            $args
        );
    }

    /**
     * Project scope for delivery orders, or null when unscoped.
     *
     * A DO's project comes from its own project_id, falling back to its PO's.
     * A DO with neither is one nobody has triaged yet — it can't belong to a
     * project until someone links it, so whoever does the receiving (pm /
     * procurement) still needs to see it. A requester never does.
     */
    private static function scopeClause(): ?array
    {
        $c = Filter::projectScope(Perm::projectIds(), 'd.project_id', 'po.project_id');
        if ($c === null) return null;
        if (!Perm::can('do_confirm')) return $c;
        return ['(' . $c[0] . ' OR (d.project_id IS NULL AND d.purchase_order_id IS NULL))', $c[1]];
    }

    /** Same scope for the aggregate queries, as a WHERE/AND fragment. */
    private static function scopeFragment(bool $and = false): array
    {
        $c = self::scopeClause();
        if ($c === null) return ['', []];
        return [($and ? ' AND ' : ' WHERE ') . $c[0], $c[1]];
    }

    /** Statuses actually present in what this user can see, for the filter dropdown. */
    public static function statuses(): array
    {
        [$w, $a] = self::scopeFragment();
        return array_column(Db::all(
            "SELECT DISTINCT d.status FROM delivery_orders d
             LEFT JOIN purchase_orders po ON po.id = d.purchase_order_id{$w}
             ORDER BY d.status", $a), 'status');
    }

    public static function count(): int
    {
        [$w, $a] = self::scopeFragment();
        return (int)Db::scalar(
            "SELECT COUNT(*) FROM delivery_orders d
             LEFT JOIN purchase_orders po ON po.id = d.purchase_order_id{$w}", $a);
    }

    /** Keep a user-supplied/uploaded file name recognisable but harmless. */
    public static function cleanFilename(string $name): ?string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f"]/', '', $name) ?? $name;
        $name = trim($name);
        return $name === '' ? null : mb_substr($name, 0, 180);
    }

    public static function lines(int $doId): array
    {
        return Db::all("SELECT * FROM do_lines WHERE delivery_order_id = ? ORDER BY line_no", [$doId]);
    }

    /** Create a DO + its lines. $lines: [description, supplier_code?, qty, uom?]. */
    public static function create(array $h, array $lines): int
    {
        return Db::tx(function () use ($h, $lines) {
            $doId = Db::insert('delivery_orders', [
                'do_number'         => trim($h['do_number'] ?? '') ?: null,
                'supplier_id'       => !empty($h['supplier_id']) ? (int)$h['supplier_id'] : null,
                'purchase_order_id' => !empty($h['purchase_order_id']) ? (int)$h['purchase_order_id'] : null,
                'project_id'        => !empty($h['project_id']) ? (int)$h['project_id'] : null,
                'po_reference_raw'  => trim($h['po_reference_raw'] ?? '') ?: null,
                'project_code_raw'  => trim($h['project_code_raw'] ?? '') ?: null,
                'delivery_date'     => ($h['delivery_date'] ?? '') ?: null,
                'image_path'        => $h['image_path'],
                'original_filename' => trim($h['original_filename'] ?? '') ?: null,
                'source_channel'    => 'manual_upload',
                'signature_present' => (array_key_exists('signature_present', $h) && $h['signature_present'] !== '' && $h['signature_present'] !== null) ? (int)(bool)$h['signature_present'] : null,
                'handwritten_notes' => trim($h['handwritten_notes'] ?? '') ?: null,
                'status'            => 'received',
            ]);
            $no = 0;
            foreach ($lines as $l) {
                $desc = trim($l['ocr_description'] ?? '');
                if ($desc === '') continue;
                $no++;
                Db::insert('do_lines', [
                    'delivery_order_id' => $doId,
                    'line_no'           => $no,
                    'ocr_description'   => $desc,
                    'ocr_supplier_code' => trim($l['ocr_supplier_code'] ?? '') ?: null,
                    'ocr_qty'           => ($l['ocr_qty'] ?? '') === '' ? null : (float)$l['ocr_qty'],
                    'ocr_uom'           => trim($l['ocr_uom'] ?? '') ?: null,
                ]);
            }
            return $doId;
        });
    }

    public static function setHeader(int $id, array $fields): void
    {
        Db::update('delivery_orders', $id, $fields);
    }

    public static function updateLine(int $lineId, array $fields): void
    {
        Db::update('do_lines', $lineId, $fields);
    }

    /** Edit the header fields a user can safely change (does not touch line matching). */
    public static function editHeader(int $id, array $h): void
    {
        Db::update('delivery_orders', $id, [
            'do_number'         => trim($h['do_number'] ?? '') ?: null,
            'delivery_date'     => trim($h['delivery_date'] ?? '') ?: null,
            'handwritten_notes' => trim($h['handwritten_notes'] ?? '') ?: null,
            // Lets a DO captured before file names were recorded be labelled by hand.
            'original_filename' => self::cleanFilename($h['original_filename'] ?? ''),
        ]);
        AuditRepo::log('delivery_order', $id, 'edit');
    }

    /**
     * Delete a DO. If it was confirmed, first reverse the receipts it posted so
     * PO balances stay correct. do_lines + OCR runs cascade on the row delete.
     */
    public static function delete(int $id): void
    {
        Db::tx(function () use ($id) {
            $posted = Db::all(
                "SELECT matched_po_line_id AS pl, qty_accepted AS qty FROM do_lines
                 WHERE delivery_order_id = ? AND is_confirmed = 1 AND matched_po_line_id IS NOT NULL",
                [$id]
            );
            $touched = [];
            foreach ($posted as $row) {
                $qty = (float)($row['qty'] ?? 0);
                $poLineId = (int)$row['pl'];
                if ($qty <= 0 || !$poLineId) continue;
                Db::q("UPDATE po_lines SET qty_received = MAX(0, qty_received - ?) WHERE id = ?", [$qty, $poLineId]);
                $pl = Db::one("SELECT purchase_order_id, qty_received, qty_ordered FROM po_lines WHERE id = ?", [$poLineId]);
                if ($pl) {
                    $recv = (float)$pl['qty_received']; $ord = (float)$pl['qty_ordered'];
                    $st = $recv > $ord + 1e-6 ? 'over_received' : (abs($recv - $ord) < 1e-6 && $ord > 0 ? 'fully_received' : ($recv > 0 ? 'partially_received' : 'open'));
                    Db::update('po_lines', $poLineId, ['line_status' => $st]);
                    $touched[(int)$pl['purchase_order_id']] = true;
                }
            }
            Db::q("DELETE FROM delivery_orders WHERE id = ?", [$id]);
            foreach (array_keys($touched) as $poId) PurchaseOrderRepo::recomputeStatus($poId);
            AuditRepo::log('delivery_order', $id, 'delete');
        });
    }
}
