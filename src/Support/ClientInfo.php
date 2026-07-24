<?php
declare(strict_types=1);

namespace App\Support;

/** Best-effort client IP + a light user-agent parser for the sign-in audit log. */
final class ClientInfo
{
    /** Real client IP, looking through common proxy/CDN headers first. */
    public static function ip(): string
    {
        // Cloudflare / common reverse-proxy headers (cPanel often sits behind one).
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
            $v = $_SERVER[$h] ?? '';
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_IP)) return $v;
        }
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($xff) && $xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
        $ra = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ra) ? $ra : '';
    }

    public static function ua(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    }

    /** @return array{os:?string,browser:?string,device_type:string} */
    public static function parseUa(string $ua): array
    {
        return [
            'os'          => self::os($ua),
            'browser'     => self::browser($ua),
            'device_type' => self::device($ua),
        ];
    }

    private static function os(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'Windows NT')                        => 'Windows',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android')                           => 'Android',
            str_contains($ua, 'CrOS')                              => 'ChromeOS',
            str_contains($ua, 'Linux')                             => 'Linux',
            default                                                => null,
        };
    }

    private static function browser(string $ua): ?string
    {
        // Order matters: Edge/Opera/Samsung masquerade as Chrome; Chrome as Safari.
        return match (true) {
            str_contains($ua, 'Edg')            => 'Edge',
            str_contains($ua, 'OPR/'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Chrome')         => 'Chrome',
            str_contains($ua, 'Firefox')        => 'Firefox',
            str_contains($ua, 'Safari')         => 'Safari',
            default                             => null,
        };
    }

    private static function device(string $ua): string
    {
        if (str_contains($ua, 'iPad') || (str_contains($ua, 'Tablet') && !str_contains($ua, 'Mobile'))) return 'Tablet';
        if (str_contains($ua, 'Mobile') || str_contains($ua, 'iPhone') || str_contains($ua, 'Android')) return 'Mobile';
        return 'Desktop';
    }
}
