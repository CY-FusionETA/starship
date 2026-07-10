<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Support\Normalizer;

final class CatalogueRepo
{
    public static function find(int $id): ?array
    {
        return Db::one("SELECT * FROM catalogue_items WHERE id = ?", [$id]);
    }

    /**
     * Search by brand / model / code / name / description.
     * Token AND-match against the denormalized search_blob (+ raw code), via LIKE.
     */
    public static function search(string $term, int $limit = 50): array
    {
        $term = trim($term);
        if ($term === '') {
            return Db::all("SELECT * FROM catalogue_items WHERE is_active = 1 ORDER BY name LIMIT ?", [$limit]);
        }
        // Each normalized token must appear in the search_blob (AND semantics).
        $tokens = array_values(array_filter(explode(' ', Normalizer::desc($term))));
        if ($tokens) {
            $where = ['is_active = 1'];
            $params = [];
            foreach ($tokens as $t) { $where[] = 'search_blob LIKE ?'; $params[] = '%' . $t . '%'; }
            $params[] = $limit;
            $rows = Db::all(
                "SELECT * FROM catalogue_items WHERE " . implode(' AND ', $where) . " ORDER BY name LIMIT ?",
                $params
            );
            if ($rows) return $rows;
        }
        // Fallback: raw substring across the visible fields (handles punctuation-heavy terms).
        $like = '%' . $term . '%';
        return Db::all(
            "SELECT * FROM catalogue_items
             WHERE is_active = 1 AND (item_code LIKE ? OR name LIKE ? OR brand LIKE ? OR model LIKE ? OR description LIKE ?)
             ORDER BY name LIMIT ?",
            [$like, $like, $like, $like, $like, $limit]
        );
    }

    public static function all(int $limit = 500): array
    {
        return Db::all("SELECT * FROM catalogue_items ORDER BY name LIMIT ?", [$limit]);
    }

    public static function save(array $data, ?int $id = null): int
    {
        $fields = [
            'item_code'      => trim($data['item_code']),
            'name'           => trim($data['name']),
            'brand'          => trim($data['brand'] ?? '') ?: null,
            'model'          => trim($data['model'] ?? '') ?: null,
            'description'    => trim($data['description'] ?? '') ?: null,
            'uom'            => Normalizer::uom($data['uom'] ?? '') ?: null,
            'xero_item_code' => trim($data['xero_item_code'] ?? '') ?: null,
            'category'       => trim($data['category'] ?? '') ?: null,
            'unit_price'     => ($data['unit_price'] ?? '') === '' ? null : (float)$data['unit_price'],
            'is_active'      => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ];
        $fields['search_blob'] = Normalizer::searchBlob($fields);

        if ($id) {
            Db::update('catalogue_items', $id, $fields);
            return $id;
        }
        return Db::insert('catalogue_items', $fields);
    }

    public static function count(): int
    {
        return (int)Db::scalar("SELECT COUNT(*) FROM catalogue_items");
    }
}
