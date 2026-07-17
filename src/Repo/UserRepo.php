<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Auth;
use App\Perm;

/** Users + which projects each one may see. Managed from Settings by the superadmin. */
final class UserRepo
{
    public const MIN_PASSWORD = 8;

    public static function find(int $id): ?array
    {
        return Db::one("SELECT * FROM users WHERE id = ?", [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Db::one("SELECT * FROM users WHERE email = ?", [self::normalizeEmail($email)]);
    }

    /** All users with their project count + names, for the Settings list. */
    public static function all(): array
    {
        $users = Db::all(
            "SELECT u.*, (SELECT COUNT(*) FROM user_projects up WHERE up.user_id = u.id) AS project_count
             FROM users u ORDER BY u.is_active DESC, u.name"
        );
        foreach ($users as &$u) {
            $u['projects'] = Perm::seesAllProjects($u['role']) ? [] : self::projects((int)$u['id']);
        }
        return $users;
    }

    /** Project ids this user is assigned to. */
    public static function projectIds(int $userId): array
    {
        return array_map('intval', array_column(
            Db::all("SELECT project_id FROM user_projects WHERE user_id = ?", [$userId]),
            'project_id'
        ));
    }

    /** Assigned projects with their codes, for display. */
    public static function projects(int $userId): array
    {
        return Db::all(
            "SELECT p.id, p.project_code, p.name
             FROM user_projects up JOIN projects p ON p.id = up.project_id
             WHERE up.user_id = ? ORDER BY p.project_code",
            [$userId]
        );
    }

    /**
     * Create a user. Returns [id, error]; error is a human message on failure.
     * $projectIds is ignored for roles that see everything anyway.
     */
    public static function create(array $in, array $projectIds = []): array
    {
        $name  = trim($in['name'] ?? '');
        $email = self::normalizeEmail($in['email'] ?? '');
        $pass  = (string)($in['password'] ?? '');
        $role  = (string)($in['role'] ?? '');

        if ($name === '')                          return [0, 'Name is required.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [0, 'A valid email address is required.'];
        if (self::findByEmail($email))             return [0, 'A user with that email already exists.'];
        if (strlen($pass) < self::MIN_PASSWORD)    return [0, 'Password must be at least ' . self::MIN_PASSWORD . ' characters.'];
        if (!in_array($role, Perm::ROLES, true))   return [0, 'Pick a valid role.'];

        $id = Db::tx(function () use ($name, $email, $pass, $role, $projectIds) {
            $id = Db::insert('users', [
                'name'          => $name,
                'email'         => $email,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'role'          => $role,
                'is_active'     => 1,
                'created_by'    => Auth::id(),
            ]);
            self::setProjects($id, $projectIds, $role);
            return $id;
        });
        AuditRepo::log('user', $id, 'create');
        return [$id, null];
    }

    /**
     * Update name/role/projects, and the password only when a new one is given
     * (blank leaves it alone — same convention as the Xero secret fields).
     */
    public static function update(int $id, array $in, array $projectIds = []): ?string
    {
        $user = self::find($id);
        if (!$user) return 'That user no longer exists.';

        $name = trim($in['name'] ?? '');
        $role = (string)($in['role'] ?? '');
        $pass = (string)($in['password'] ?? '');

        if ($name === '')                        return 'Name is required.';
        if (!in_array($role, Perm::ROLES, true)) return 'Pick a valid role.';
        if ($pass !== '' && strlen($pass) < self::MIN_PASSWORD) {
            return 'Password must be at least ' . self::MIN_PASSWORD . ' characters.';
        }
        // Don't let the last superadmin demote themselves out of existence.
        if ($user['role'] === 'admin' && $role !== 'admin' && self::activeAdminCount() <= 1) {
            return 'This is the only superadmin — promote someone else first.';
        }

        Db::tx(function () use ($id, $name, $role, $pass, $projectIds) {
            $fields = ['name' => $name, 'role' => $role];
            if ($pass !== '') $fields['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
            Db::update('users', $id, $fields);
            self::setProjects($id, $projectIds, $role);
        });
        AuditRepo::log('user', $id, $pass !== '' ? 'update+password' : 'update');
        return null;
    }

    /** Replace a user's project assignments. Unscoped roles keep an empty list. */
    public static function setProjects(int $userId, array $projectIds, ?string $role = null): void
    {
        $role = $role ?? (string)(self::find($userId)['role'] ?? '');
        Db::q("DELETE FROM user_projects WHERE user_id = ?", [$userId]);
        if (Perm::seesAllProjects($role)) return;   // sees everything; a list would be misleading
        $seen = [];
        foreach ($projectIds as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0 || isset($seen[$pid])) continue;
            if (!Db::scalar("SELECT 1 FROM projects WHERE id = ?", [$pid])) continue;
            Db::insert('user_projects', ['user_id' => $userId, 'project_id' => $pid]);
            $seen[$pid] = true;
        }
    }

    /** Deactivate (never hard-delete: requisitions and audit rows point at users). */
    public static function setActive(int $id, bool $active): ?string
    {
        $user = self::find($id);
        if (!$user) return 'That user no longer exists.';
        if (!$active && (int)$id === (int)Auth::id())   return 'You cannot deactivate your own account.';
        if (!$active && $user['role'] === 'admin' && self::activeAdminCount() <= 1) {
            return 'This is the only superadmin — promote someone else first.';
        }
        Db::update('users', $id, ['is_active' => $active ? 1 : 0]);
        AuditRepo::log('user', $id, $active ? 'activate' : 'deactivate');
        return null;
    }

    public static function activeAdminCount(): int
    {
        return (int)Db::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
    }

    public static function count(): int
    {
        return (int)Db::scalar("SELECT COUNT(*) FROM users WHERE is_active = 1");
    }

    private static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
