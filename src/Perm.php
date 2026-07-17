<?php
declare(strict_types=1);

namespace App;

use App\Repo\UserRepo;

/**
 * One place that answers "who can do what" and "who can see which projects".
 *
 * Roles
 *   admin       Superadmin — everything, plus Settings/users. Sees all projects.
 *   pm          Project Manager — approves MRs, approves over-deliveries, sees
 *               money. Scoped to assigned projects.
 *   procurement Raises POs, captures DOs, posts receipts, sees money. Scoped.
 *   requester   Raises MRs + attaches quotations. NO money. Scoped.
 *   finance     Read-only across ALL projects, sees money + bills. Cannot
 *               approve, edit or delete anything.
 *
 * Capability checks live here rather than in route guards so the rules read as
 * one table instead of being scattered across index.php. Auth::requireRole()
 * still exists for coarse gating, but note it lets `admin` pass everything.
 */
final class Perm
{
    public const ROLES = ['admin', 'pm', 'procurement', 'requester', 'finance'];

    public const LABELS = [
        'admin'       => 'Superadmin',
        'pm'          => 'Project Manager',
        'procurement' => 'Procurement',
        'requester'   => 'Requester',
        'finance'     => 'Finance',
    ];

    public const DESCRIPTIONS = [
        'admin'       => 'Everything, including Settings, users and deletes. Sees all projects.',
        'pm'          => 'Approves requisitions and over-deliveries. Sees costs. Assigned projects only.',
        'procurement' => 'Raises POs, captures DOs, posts receipts. Sees costs. Assigned projects only.',
        'requester'   => 'Raises requisitions and attaches quotations. Cannot see costs. Assigned projects only.',
        'finance'     => 'Read-only across all projects. Sees costs and bills. Cannot approve or edit.',
    ];

    /**
     * Capability → roles allowed. Anything not listed is superadmin-only.
     * 'admin' is listed explicitly rather than special-cased, so this table is
     * the whole truth and can be read top to bottom.
     */
    private const CAPS = [
        // Money: unit prices, line totals, PO totals, Xero state.
        'view_money'        => ['admin', 'pm', 'procurement', 'finance'],
        // Requisitions
        'mr_create'         => ['admin', 'pm', 'procurement', 'requester'],
        'mr_edit'           => ['admin', 'pm', 'procurement', 'requester'],
        'mr_approve'        => ['admin', 'pm'],
        // Purchase orders
        'po_create'         => ['admin', 'pm', 'procurement'],
        'po_xero_sync'      => ['admin', 'procurement'],
        // Delivery orders / receiving
        'do_capture'        => ['admin', 'pm', 'procurement'],
        'do_confirm'        => ['admin', 'pm', 'procurement'],
        'do_approve_over'   => ['admin', 'pm'],   // sign off an over-delivery
        // Master data
        'master_edit'       => ['admin', 'pm', 'procurement'],  // catalogue, suppliers, projects
        // Superadmin-only: 'settings', 'users', 'delete_records' fall through.
    ];

    /** Roles that see every project regardless of user_projects. */
    private const UNSCOPED_ROLES = ['admin', 'finance'];

    public static function role(): string
    {
        return Auth::role();
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** Can the current user do $cap? */
    public static function can(string $cap): bool
    {
        $role = self::role();
        if ($role === '') return false;
        if ($role === 'admin') return true;                 // superadmin passes everything
        return in_array($role, self::CAPS[$cap] ?? [], true);
    }

    /** 403 unless the current user has $cap. */
    public static function require(string $cap): void
    {
        Auth::require();
        if (!self::can($cap)) {
            http_response_code(403);
            Response::partial('error', ['code' => 403, 'message' => 'You do not have access to that.']);
            exit;
        }
    }

    public static function label(?string $role = null): string
    {
        $role = $role ?? self::role();
        return self::LABELS[$role] ?? ucfirst($role ?: 'Guest');
    }

    /** True when this role sees every project (superadmin, finance). */
    public static function seesAllProjects(?string $role = null): bool
    {
        return in_array($role ?? self::role(), self::UNSCOPED_ROLES, true);
    }

    /**
     * Project ids the current user may see, or null for "everything".
     * An empty array means "assigned to nothing" — they see no records at all,
     * which is deliberate: access is granted per project, never by default.
     */
    public static function projectIds(): ?array
    {
        if (!Auth::check()) return [];
        if (self::seesAllProjects()) return null;
        return UserRepo::projectIds((int)Auth::id());
    }

    /** Can the current user see this specific project's records? */
    public static function canSeeProject(?int $projectId): bool
    {
        $ids = self::projectIds();
        if ($ids === null) return true;
        if ($projectId === null) return false;
        return in_array($projectId, $ids, true);
    }

    /**
     * Delivery orders take their project from their own project_id, falling back
     * to the PO's. A DO with neither is untriaged and belongs to no project yet,
     * so whoever does the receiving can still open it. Mirrors the list scope in
     * DeliveryOrderRepo::scopeClause().
     */
    public static function canSeeDelivery(array $do): bool
    {
        if (self::projectIds() === null) return true;
        $pid = $do['project_id'] ?? $do['po_project_id'] ?? null;
        if ($pid !== null) return self::canSeeProject((int)$pid);
        $untriaged = empty($do['project_id']) && empty($do['purchase_order_id']);
        return $untriaged && self::can('do_confirm');
    }

    /**
     * Not-found rather than forbidden when a record is outside your projects:
     * a 403 would confirm the record exists and leak its id.
     */
    public static function requireProject(?int $projectId): void
    {
        Auth::require();
        if (!self::canSeeProject($projectId)) Response::notFound();
    }
}
