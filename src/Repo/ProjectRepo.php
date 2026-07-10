<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Support\Normalizer;

final class ProjectRepo
{
    public static function find(int $id): ?array
    {
        return Db::one("SELECT * FROM projects WHERE id = ?", [$id]);
    }

    public static function all(): array
    {
        return Db::all("SELECT * FROM projects ORDER BY name");
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
