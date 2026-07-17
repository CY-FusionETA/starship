<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Perm;
use App\Support\Normalizer;

final class ProjectRepo
{
    public static function find(int $id): ?array
    {
        return Db::one("SELECT * FROM projects WHERE id = ?", [$id]);
    }

    /**
     * Every project. Used by system contexts (matching, seed, Xero sync) and by
     * the superadmin's user-assignment UI — anything user-facing wants
     * allForUser() instead, or a requester sees projects they can't open.
     */
    public static function all(): array
    {
        return Db::all("SELECT * FROM projects ORDER BY name");
    }

    /** Projects the current user is assigned to (all of them for superadmin/finance). */
    public static function allForUser(): array
    {
        $ids = Perm::projectIds();
        if ($ids === null) return self::all();
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        return Db::all("SELECT * FROM projects WHERE id IN ($in) ORDER BY name", array_map('intval', $ids));
    }

    public static function byCodeNorm(string $code): ?array
    {
        return Db::one("SELECT * FROM projects WHERE project_code_norm = ?", [Normalizer::projectCode($code)]);
    }

    public static function save(array $data, ?int $id = null): int
    {
        $fields = [
            'project_code'      => trim($data['project_code']),
            'project_code_norm' => Normalizer::projectCode($data['project_code']),
            'name'              => trim($data['name']),
            'site_address'      => trim($data['site_address'] ?? '') ?: null,
            'is_active'         => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ];
        if ($id) { Db::update('projects', $id, $fields); return $id; }
        return Db::insert('projects', $fields);
    }
}
