<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Auth;

final class AuditRepo
{
    public static function log(string $entityType, int $entityId, string $action, ?array $detail = null): void
    {
        Db::insert('audit_log', [
            'user_id'     => Auth::id(),
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'detail_json' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
