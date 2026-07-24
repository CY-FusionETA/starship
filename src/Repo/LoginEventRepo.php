<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Support\ClientInfo;
use App\Service\GeoIp;

/** Sign-in audit trail: account, IP, location and device per login attempt. */
final class LoginEventRepo
{
    /**
     * Record one sign-in attempt. Best-effort throughout — auditing must never
     * break the actual login, so everything is wrapped.
     */
    public static function record(?int $userId, string $email, bool $success): void
    {
        try {
            $ua  = ClientInfo::ua();
            $p   = ClientInfo::parseUa($ua);
            $ip  = ClientInfo::ip();
            $geo = GeoIp::lookup($ip);   // cached per IP; short timeout; skips private IPs

            Db::insert('login_events', [
                'user_id'     => $userId,
                'email'       => substr(strtolower(trim($email)), 0, 190),
                'success'     => $success ? 1 : 0,
                'ip'          => $ip,
                'user_agent'  => $ua,
                'os'          => $p['os'],
                'browser'     => $p['browser'],
                'device_type' => $p['device_type'],
                'country'     => $geo['country'],
                'city'        => $geo['city'],
                'isp'         => $geo['isp'],
            ]);
        } catch (\Throwable $e) {
            error_log('login audit failed: ' . $e->getMessage());
        }
    }

    /** Recent sign-ins, newest first, with the account name/role joined in. */
    public static function recent(int $limit = 200): array
    {
        return Db::all(
            "SELECT le.*, u.name AS user_name, u.role AS user_role
               FROM login_events le
               LEFT JOIN users u ON u.id = le.user_id
              ORDER BY le.id DESC
              LIMIT ?",
            [max(1, min(1000, $limit))]
        );
    }

    /** Small headline stats for the top of the log. */
    public static function stats(): array
    {
        return [
            'total'    => (int)Db::scalar("SELECT COUNT(*) FROM login_events"),
            'failed'   => (int)Db::scalar("SELECT COUNT(*) FROM login_events WHERE success = 0"),
            'accounts' => (int)Db::scalar("SELECT COUNT(DISTINCT user_id) FROM login_events WHERE user_id IS NOT NULL"),
            'ips'      => (int)Db::scalar("SELECT COUNT(DISTINCT ip) FROM login_events WHERE ip IS NOT NULL AND ip != ''"),
            'last24'   => (int)Db::scalar("SELECT COUNT(*) FROM login_events WHERE created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)]),
        ];
    }
}
