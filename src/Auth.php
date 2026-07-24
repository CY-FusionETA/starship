<?php
declare(strict_types=1);

namespace App;

/** Session-based authentication + role gates. */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
            session_start();
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        $u = Db::one("SELECT * FROM users WHERE email = ? AND is_active = 1", [strtolower(trim($email))]);
        if (!$u || !password_verify($password, $u['password_hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['uid']   = (int)$u['id'];
        $_SESSION['role']  = $u['role'];
        $_SESSION['name']  = $u['name'];
        $_SESSION['email'] = $u['email'];
        Db::update('users', (int)$u['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * Re-read role + is_active from the DB once per request.
     *
     * The session caches them at login, so without this a role change or a
     * deactivation wouldn't take effect until the user next signed in — a
     * revoked account would keep its access for as long as it stayed logged in.
     */
    public static function refresh(): void
    {
        if (empty($_SESSION['uid'])) return;
        $u = Db::one("SELECT role, name, email, is_active FROM users WHERE id = ?", [(int)$_SESSION['uid']]);
        if (!$u || (int)$u['is_active'] !== 1) { self::logout(); return; }
        $_SESSION['role']  = $u['role'];
        $_SESSION['name']  = $u['name'];
        $_SESSION['email'] = $u['email'];
    }

    /**
     * The single owner account — the only one allowed to see the sign-in audit
     * log, even above other admins. Defaults to Simon; override with
     * config app.owner_email.
     */
    public static function ownerEmail(): string
    {
        return strtolower(trim((string)cfg('app.owner_email', 'simon@fusioneta.com')));
    }

    public static function isOwner(): bool
    {
        $owner = self::ownerEmail();
        $email = strtolower(trim((string)($_SESSION['email'] ?? '')));
        return $owner !== '' && $email === $owner;
    }

    /** Is this email the owner account? Used to keep it out of the user list. */
    public static function isOwnerEmail(?string $email): bool
    {
        $owner = self::ownerEmail();
        return $owner !== '' && strtolower(trim((string)$email)) === $owner;
    }

    public static function check(): bool { return !empty($_SESSION['uid']); }

    /** Current user's role, or '' if signed out. */
    public static function role(): string { return (string)($_SESSION['role'] ?? ''); }

    /** Superadmin (Simon) — may approve requisitions and delete catalogue items. */
    public static function isAdmin(): bool { return self::role() === 'admin'; }

    /** True when the current role is admin OR one of the given roles. */
    public static function is(string ...$roles): bool
    {
        return self::isAdmin() || in_array(self::role(), $roles, true);
    }

    /** Human label for the current role (used in the top bar). */
    public static function roleLabel(): string
    {
        return Perm::label();
    }

    public static function user(): ?array
    {
        if (!self::check()) return null;
        return ['id' => $_SESSION['uid'], 'role' => $_SESSION['role'] ?? 'viewer', 'name' => $_SESSION['name'] ?? ''];
    }

    public static function id(): ?int { return self::check() ? (int)$_SESSION['uid'] : null; }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Redirect to login if not authenticated. */
    public static function require(): void
    {
        if (!self::check()) { Response::redirect('/login'); }
    }

    /** Require one of the given roles, else 403. */
    public static function requireRole(string ...$roles): void
    {
        self::require();
        if (!in_array($_SESSION['role'] ?? '', $roles, true) && ($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}
