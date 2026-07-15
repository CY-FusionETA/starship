<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;

/**
 * Role-aware, urgency-driven dashboard feeds.
 *
 * The dashboard's job is to surface a prioritised worklist so nothing urgent
 * slips — PMs clear the approval queue, staff chase deliveries — which is what
 * moves the KPI needle. Every feed is ordered by urgency then delivery
 * proximity, and carries the derived numbers the view needs (days waiting,
 * days-to-delivery, line count) so the template stays dumb.
 */
final class DashboardRepo
{
    /** Lower = more urgent. Mirrors the Urgency picker on the MR form. */
    private const URGENCY_RANK = "CASE r.urgency
        WHEN 'ASAP/URGENT' THEN 1
        WHEN 'ASAP - Partial Delivery Accepted' THEN 2
        WHEN 'Specify Date Below' THEN 3
        WHEN 'TBA - To Be Advised' THEN 4
        ELSE 5 END";

    /** Statuses that still need somebody to act. */
    private const OPEN = "('draft','approved','partially_ordered')";

    /** Shared feed: header row + project + derived priority columns. */
    private static function feed(string $where, array $params, string $order, int $limit): array
    {
        $rank = self::URGENCY_RANK;
        $params[] = $limit;
        return Db::all(
            "SELECT r.*, p.name AS project_name, p.project_code,
                    (SELECT COUNT(*) FROM requisition_lines l WHERE l.requisition_id = r.id) AS line_count,
                    ($rank) AS urgency_rank,
                    CAST(julianday(date('now','localtime')) - julianday(date(r.created_at)) AS INTEGER) AS days_waiting,
                    CASE WHEN r.delivery_date IS NULL OR r.delivery_date = '' THEN NULL
                         ELSE CAST(julianday(date(r.delivery_date)) - julianday(date('now','localtime')) AS INTEGER)
                    END AS days_to_delivery
             FROM requisitions r JOIN projects p ON p.id = r.project_id
             WHERE $where
             ORDER BY $order
             LIMIT ?",
            $params
        );
    }

    /** PM view — requisitions awaiting approval, most urgent / longest-waiting first. */
    public static function pendingApprovals(int $limit = 8): array
    {
        return self::feed(
            "r.status = 'draft'",
            [],
            self::URGENCY_RANK . " ASC, (r.delivery_date IS NULL) ASC, r.delivery_date ASC, r.created_at ASC",
            $limit
        );
    }

    /** Approved but no PO raised yet — the next bottleneck after approval. */
    public static function readyToOrder(int $limit = 8): array
    {
        return self::feed(
            "r.status = 'approved'",
            [],
            self::URGENCY_RANK . " ASC, (r.delivery_date IS NULL) ASC, r.delivery_date ASC, r.created_at ASC",
            $limit
        );
    }

    /** Open MRs with a delivery date today or later — staff's chase list. */
    public static function upcomingDeliveries(int $limit = 8): array
    {
        return self::feed(
            "r.status IN " . self::OPEN . " AND r.delivery_date IS NOT NULL AND r.delivery_date != ''
             AND date(r.delivery_date) >= date('now','localtime')",
            [],
            "r.delivery_date ASC, " . self::URGENCY_RANK . " ASC",
            $limit
        );
    }

    /** Open MRs whose delivery date has already passed — at risk, chase now. */
    public static function overdue(int $limit = 8): array
    {
        return self::feed(
            "r.status IN " . self::OPEN . " AND r.delivery_date IS NOT NULL AND r.delivery_date != ''
             AND date(r.delivery_date) < date('now','localtime')",
            [],
            "r.delivery_date ASC",
            $limit
        );
    }

    /** A person's own open requisitions, prioritised. */
    public static function myOpen(int $userId, int $limit = 8): array
    {
        if ($userId <= 0) return [];
        return self::feed(
            "r.created_by = ? AND r.status IN " . self::OPEN,
            [$userId],
            self::URGENCY_RANK . " ASC, (r.delivery_date IS NULL) ASC, r.delivery_date ASC, r.created_at ASC",
            $limit
        );
    }

    /** Headline KPI counters. $userId scopes the personal ones (0 = org-wide). */
    public static function kpis(int $userId = 0): array
    {
        $s = fn(string $sql, array $p = []) => (int)Db::scalar($sql, $p);
        return [
            'pending'   => $s("SELECT COUNT(*) FROM requisitions WHERE status = 'draft'"),
            'urgent'    => $s("SELECT COUNT(*) FROM requisitions WHERE status = 'draft' AND urgency LIKE 'ASAP%'"),
            'ready'     => $s("SELECT COUNT(*) FROM requisitions WHERE status = 'approved'"),
            'overdue'   => $s("SELECT COUNT(*) FROM requisitions WHERE status IN " . self::OPEN . "
                               AND delivery_date IS NOT NULL AND delivery_date != ''
                               AND date(delivery_date) < date('now','localtime')"),
            'due_week'  => $s("SELECT COUNT(*) FROM requisitions WHERE status IN " . self::OPEN . "
                               AND delivery_date IS NOT NULL AND delivery_date != ''
                               AND date(delivery_date) BETWEEN date('now','localtime') AND date('now','localtime','+7 days')"),
            'avg_wait'  => (float)(Db::scalar("SELECT ROUND(AVG(julianday(date('now','localtime')) - julianday(date(created_at))),1)
                               FROM requisitions WHERE status = 'draft'") ?? 0),
            'my_open'   => $userId > 0
                ? $s("SELECT COUNT(*) FROM requisitions WHERE created_by = ? AND status IN " . self::OPEN, [$userId])
                : $s("SELECT COUNT(*) FROM requisitions WHERE status IN " . self::OPEN),
        ];
    }
}
