<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Perm;
use App\Support\Filter;

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

    /**
     * "AND project_id IN (…)" for the current user, or '' when unscoped.
     * Every feed and counter goes through this: a dashboard that counted other
     * projects' requisitions would leak them, and its feeds would link to
     * records the user can only 404 on.
     */
    private static function scope(string $col = 'r.project_id'): array
    {
        $c = Filter::projectScope(Perm::projectIds(), $col);
        return $c === null ? ['', []] : [' AND ' . $c[0], $c[1]];
    }

    /** Shared feed: header row + project + derived priority columns. */
    private static function feed(string $where, array $params, string $order, int $limit): array
    {
        $rank = self::URGENCY_RANK;
        [$scopeSql, $scopeArgs] = self::scope();
        $where .= $scopeSql;
        $params = array_merge($params, $scopeArgs);
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

    /** Headline KPI counters, scoped to the user's projects. $userId scopes the personal ones. */
    public static function kpis(int $userId = 0): array
    {
        [$sc, $sa] = self::scope('project_id');   // these count straight off requisitions, no alias
        $s = fn(string $sql, array $p = []) => (int)Db::scalar($sql, array_merge($p, $sa));
        return [
            'pending'   => $s("SELECT COUNT(*) FROM requisitions WHERE status = 'draft'{$sc}"),
            'urgent'    => $s("SELECT COUNT(*) FROM requisitions WHERE status = 'draft' AND urgency LIKE 'ASAP%'{$sc}"),
            'ready'     => $s("SELECT COUNT(*) FROM requisitions WHERE status = 'approved'{$sc}"),
            'overdue'   => $s("SELECT COUNT(*) FROM requisitions WHERE status IN " . self::OPEN . "
                               AND delivery_date IS NOT NULL AND delivery_date != ''
                               AND date(delivery_date) < date('now','localtime'){$sc}"),
            'due_week'  => $s("SELECT COUNT(*) FROM requisitions WHERE status IN " . self::OPEN . "
                               AND delivery_date IS NOT NULL AND delivery_date != ''
                               AND date(delivery_date) BETWEEN date('now','localtime') AND date('now','localtime','+7 days'){$sc}"),
            'avg_wait'  => (float)(Db::scalar("SELECT ROUND(AVG(julianday(date('now','localtime')) - julianday(date(created_at))),1)
                               FROM requisitions WHERE status = 'draft'{$sc}", $sa) ?? 0),
            'my_open'   => $userId > 0
                ? $s("SELECT COUNT(*) FROM requisitions WHERE created_by = ? AND status IN " . self::OPEN . $sc, [$userId])
                : $s("SELECT COUNT(*) FROM requisitions WHERE status IN " . self::OPEN . $sc),
        ];
    }

    /**
     * Stage counters for the live "pipeline pulse" strip: how much work is
     * sitting at each step of MR → approval → PO → delivery → match right now.
     * Same project scoping as the KPIs so nothing leaks across projects.
     */
    public static function pulse(): array
    {
        [$sc, $sa] = self::scope('project_id');
        $s = fn(string $sql, array $p = []) => (int)Db::scalar($sql, array_merge($p, $sa));
        return [
            'mr_draft'    => $s("SELECT COUNT(*) FROM requisitions WHERE status = 'draft'{$sc}"),
            'mr_approved' => $s("SELECT COUNT(*) FROM requisitions WHERE status IN ('approved','partially_ordered'){$sc}"),
            'po_open'     => $s("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('issued','partially_received'){$sc}"),
            'do_review'   => $s("SELECT COUNT(*) FROM delivery_orders WHERE status IN ('received','needs_review'){$sc}"),
            'do_matched'  => $s("SELECT COUNT(*) FROM delivery_orders WHERE status = 'matched'{$sc}"),
            'exceptions'  => $s("SELECT COUNT(*) FROM delivery_orders WHERE status = 'exception'{$sc}"),
        ];
    }
}
