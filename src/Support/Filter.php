<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Builds the WHERE clause for the list screens (requisitions / POs / DOs).
 *
 * Each helper returns either null (filter not in use — skipped) or a
 * [sql, args] pair. Column names are supplied by the calling repo and never
 * come from the request; only values are bound, so a filter can't inject SQL.
 */
final class Filter
{
    /** Combine the given clauses into "WHERE a AND b" + the bound args. */
    public static function build(array $clauses): array
    {
        $sql = []; $args = [];
        foreach ($clauses as $c) {
            if ($c === null) continue;
            $sql[] = $c[0];
            foreach ($c[1] as $a) $args[] = $a;
        }
        return [$sql ? 'WHERE ' . implode(' AND ', $sql) : '', $args];
    }

    /** Free-text search across several columns (case-insensitive contains). */
    public static function search(string $q, array $columns): ?array
    {
        $q = trim($q);
        if ($q === '' || !$columns) return null;
        // Escape the LIKE wildcards so a literal % or _ searches as typed.
        $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
        $parts = []; $args = [];
        foreach ($columns as $col) {
            $parts[] = "LOWER(COALESCE($col, '')) LIKE ? ESCAPE '\\'";
            $args[] = $term;
        }
        return ['(' . implode(' OR ', $parts) . ')', $args];
    }

    /** Exact match, skipped when the value is blank ("all"). */
    public static function equals(string $column, $value): ?array
    {
        if ($value === '' || $value === null) return null;
        return ["$column = ?", [$value]];
    }

    public static function dateFrom(string $column, string $date): ?array
    {
        $date = trim($date);
        if ($date === '') return null;
        return ["($column IS NOT NULL AND $column != '' AND $column >= ?)", [$date]];
    }

    public static function dateTo(string $column, string $date): ?array
    {
        $date = trim($date);
        if ($date === '') return null;
        return ["($column IS NOT NULL AND $column != '' AND $column <= ?)", [$date]];
    }

    /** True when any filter is actually narrowing the list. */
    public static function active(array $f): bool
    {
        foreach ($f as $v) { if (trim((string)$v) !== '') return true; }
        return false;
    }

    /**
     * Restrict a list to the projects the current user may see.
     *
     * $ids null  → unscoped (superadmin / finance): no clause.
     * $ids []    → assigned to nothing: match nothing. Access is granted per
     *              project, so "no projects" must mean "no records", never "all".
     *
     * $extraColumn lets a delivery order fall back to its PO's project when its
     * own project_id hasn't been resolved yet.
     */
    public static function projectScope(?array $ids, string $column, ?string $extraColumn = null): ?array
    {
        if ($ids === null) return null;
        if (!$ids) return ['1 = 0', []];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $args = array_map('intval', $ids);
        if ($extraColumn === null) return ["$column IN ($in)", $args];
        return ["(COALESCE($column, $extraColumn) IN ($in))", $args];
    }
}
