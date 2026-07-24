<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;

/**
 * Best-effort IP → location. Cached per IP in ip_geo so a given address is
 * looked up once. Private/reserved IPs are skipped. Never throws — a failed
 * lookup just returns nulls, so it can't break the sign-in it runs during.
 */
final class GeoIp
{
    /** @return array{country:?string,region:?string,city:?string,isp:?string} */
    public static function lookup(string $ip): array
    {
        $none = ['country' => null, 'region' => null, 'city' => null, 'isp' => null];
        if ($ip === '' || self::isPrivate($ip)) return $none;

        try {
            $row = Db::one("SELECT country, region, city, isp FROM ip_geo WHERE ip = ?", [$ip]);
            if ($row) return $row;
        } catch (\Throwable $e) { return $none; }

        $g = self::fetch($ip);
        // Only cache real hits — a transient network failure shouldn't stick a
        // permanent blank against this IP.
        if ($g['country'] !== null || $g['city'] !== null) {
            try {
                Db::q(
                    "INSERT OR REPLACE INTO ip_geo (ip, country, region, city, isp, resolved_at) VALUES (?,?,?,?,?,?)",
                    [$ip, $g['country'], $g['region'], $g['city'], $g['isp'], date('Y-m-d H:i:s')]
                );
            } catch (\Throwable $e) { /* cache best-effort */ }
        }
        return $g;
    }

    /** @return array{country:?string,region:?string,city:?string,isp:?string} */
    private static function fetch(string $ip): array
    {
        $none = ['country' => null, 'region' => null, 'city' => null, 'isp' => null];
        try {
            $ch = curl_init('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country,region,city,connection');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300 && is_string($body)) {
                $d = json_decode($body, true);
                if (is_array($d) && !empty($d['success'])) {
                    return [
                        'country' => $d['country'] ?? null,
                        'region'  => $d['region'] ?? null,
                        'city'    => $d['city'] ?? null,
                        'isp'     => $d['connection']['isp'] ?? ($d['connection']['org'] ?? null),
                    ];
                }
            }
        } catch (\Throwable $e) { /* fall through */ }
        return $none;
    }

    /** True for private, reserved, or non-IP strings (nothing to geolocate). */
    public static function isPrivate(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
