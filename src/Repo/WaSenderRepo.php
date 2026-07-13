<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;

/** Allowlist of WhatsApp numbers permitted to submit DOs/invoices via the Wazzup hotline. */
final class WaSenderRepo
{
    /** Normalise a phone to bare digits (E.164 without '+'), e.g. "+60 10-230 0975" -> "60102300975". */
    public static function normalize(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public static function all(): array
    {
        return Db::all("SELECT * FROM wa_senders ORDER BY is_active DESC, name, phone_e164");
    }

    public static function add(string $phone, ?string $name): void
    {
        $e164 = self::normalize($phone);
        if ($e164 === '') return;
        Db::q(
            "INSERT INTO wa_senders (phone_e164, name, is_active) VALUES (?, ?, 1)
             ON CONFLICT(phone_e164) DO UPDATE SET name = excluded.name, is_active = 1",
            [$e164, trim((string)$name) ?: null]
        );
    }

    public static function delete(int $id): void
    {
        Db::q("DELETE FROM wa_senders WHERE id = ?", [$id]);
    }

    /** Is this incoming number allowed (active)? Matches on normalized digits. */
    public static function isAllowed(string $phone): bool
    {
        $e164 = self::normalize($phone);
        if ($e164 === '') return false;
        return (bool)Db::scalar("SELECT 1 FROM wa_senders WHERE phone_e164 = ? AND is_active = 1", [$e164]);
    }

    public static function find(string $phone): ?array
    {
        return Db::one("SELECT * FROM wa_senders WHERE phone_e164 = ?", [self::normalize($phone)]);
    }

    /** Record that we just received a message from this sender. */
    public static function touch(string $phone): void
    {
        Db::q("UPDATE wa_senders SET last_seen_at = ? WHERE phone_e164 = ?",
            [date('Y-m-d H:i:s'), self::normalize($phone)]);
    }
}
