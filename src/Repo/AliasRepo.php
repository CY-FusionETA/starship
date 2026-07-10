<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Support\Normalizer;

/** Supplier-wording -> catalogue item map. Self-learning via learn(). */
final class AliasRepo
{
    public static function forSupplier(int $supplierId): array
    {
        return Db::all(
            "SELECT a.*, c.name AS item_name, c.item_code
             FROM item_supplier_aliases a
             JOIN catalogue_items c ON c.id = a.catalogue_item_id
             WHERE a.supplier_id = ? ORDER BY a.supplier_desc",
            [$supplierId]
        );
    }

    public static function all(): array
    {
        return Db::all(
            "SELECT a.*, c.name AS item_name, c.item_code, s.name AS supplier_name
             FROM item_supplier_aliases a
             JOIN catalogue_items c ON c.id = a.catalogue_item_id
             JOIN suppliers s ON s.id = a.supplier_id
             ORDER BY s.name, a.supplier_desc"
        );
    }

    public static function byPartCode(int $supplierId, string $code): ?array
    {
        $code = trim($code);
        if ($code === '') return null;
        return Db::one(
            "SELECT * FROM item_supplier_aliases WHERE supplier_id = ? AND supplier_part_code = ?",
            [$supplierId, $code]
        );
    }

    /** Exact match on the normalized supplier description (learned wording). */
    public static function byDescNorm(int $supplierId, string $descNorm): ?array
    {
        if ($descNorm === '') return null;
        return Db::one(
            "SELECT * FROM item_supplier_aliases WHERE supplier_id = ? AND desc_norm = ? ORDER BY times_confirmed DESC LIMIT 1",
            [$supplierId, $descNorm]
        );
    }

    public static function save(array $data, ?int $id = null): int
    {
        $fields = [
            'catalogue_item_id'  => (int)$data['catalogue_item_id'],
            'supplier_id'        => (int)$data['supplier_id'],
            'supplier_part_code' => trim($data['supplier_part_code'] ?? '') ?: null,
            'supplier_desc'      => trim($data['supplier_desc']),
            'supplier_uom'       => Normalizer::uom($data['supplier_uom'] ?? '') ?: null,
        ];
        $fields['desc_norm'] = Normalizer::desc($fields['supplier_desc']);
        if ($id) { Db::update('item_supplier_aliases', $id, $fields); return $id; }
        return Db::insert('item_supplier_aliases', $fields);
    }

    /** Learn/strengthen an alias after a human confirms a match. */
    public static function learn(int $supplierId, ?string $partCode, string $desc, int $catalogueItemId): void
    {
        $descNorm = Normalizer::desc($desc);
        $existing = Db::one(
            "SELECT id FROM item_supplier_aliases
             WHERE supplier_id = ? AND catalogue_item_id = ? AND desc_norm = ?",
            [$supplierId, $catalogueItemId, $descNorm]
        );
        if ($existing) {
            Db::q("UPDATE item_supplier_aliases SET times_confirmed = times_confirmed + 1 WHERE id = ?", [$existing['id']]);
            return;
        }
        Db::insert('item_supplier_aliases', [
            'catalogue_item_id'  => $catalogueItemId,
            'supplier_id'        => $supplierId,
            'supplier_part_code' => $partCode ? trim($partCode) : null,
            'supplier_desc'      => trim($desc),
            'desc_norm'          => $descNorm,
            'times_confirmed'    => 1,
        ]);
    }
}
