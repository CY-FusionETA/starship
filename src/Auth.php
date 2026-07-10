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
        $_SESSION['uid']  = (int)$u['id'];
        $_SESSION['role'] = $u['role'];
        $_SESSION['name'] = $u['name'];
        Db::update('users', (int)$u['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
        return true;
    }

    public static function check(): bool { return !empty($_SESSION['uid']); }

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
