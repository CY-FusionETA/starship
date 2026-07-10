<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;

final class DeliveryOrderRepo
{
    public static function find(int $id): ?array
    {
        return Db::one(
            "SELECT d.*, s.name AS supplier_name, p.project_code, po.po_number
             FROM delivery_orders d
             LEFT JOIN suppliers s ON s.id = d.supplier_id
             LEFT JOIN projects p ON p.id = d.project_id
             LEFT JOIN purchase_orders po ON po.id = d.purchase_order_id
             WHERE d.id = ?",
            [$id]
        );
    }

    public static function all(): array
    {
        return Db::all(
            "SELECT d.*, s.name AS supplier_name, po.po_number
             FROM delivery_orders d
             LEFT JOIN suppliers s ON s.id = d.supplier_id
             LEFT JOIN purchase_orders po ON po.id = d.purchase_order_id
             ORDER BY d.created_at DESC"
        );
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
}
