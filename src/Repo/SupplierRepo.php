<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;

final class SupplierRepo
{
    public static function find(int $id): ?array
    {
        return Db::one("SELECT * FROM suppliers WHERE id = ?", [$id]);
    }

    public static function all(): array
    {
        return Db::all("SELECT * FROM suppliers ORDER BY name");
    }

    /** Active suppliers only — for pickers (hides the 'To Be Confirmed' placeholder). */
    public static function active(): array
    {
        return Db::all("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name");
    }

    public static function save(array $data, ?int $id = null): int
    {
        $fields = [
            'name'          => trim($data['name']),
            'short_code'    => trim($data['short_code'] ?? '') ?: null,
            'phone'         => trim($data['phone'] ?? '') ?: null,
            'whatsapp_e164' => trim($data['whatsapp_e164'] ?? '') ?: null,
            'email'         => trim($data['email'] ?? '') ?: null,
            'sst_reg_no'    => trim($data['sst_reg_no'] ?? '') ?: null,
            'myinvois_tin'  => trim($data['myinvois_tin'] ?? '') ?: null,
            'po_number_hint'=> trim($data['po_number_hint'] ?? '') ?: null,
            'is_active'     => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ];
        if ($id) { Db::update('suppliers', $id, $fields); return $id; }
        return Db::insert('suppliers', $fields);
    }

    public static function byWhatsapp(string $e164): ?array
    {
        return Db::one("SELECT * FROM suppliers WHERE whatsapp_e164 = ?", [$e164]);
    }

    /** Loose match of an OCR'd supplier name to a supplier record (first significant token). */
    public static function matchByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') return null;
        $r = Db::one("SELECT * FROM suppliers WHERE LOWER(name) = LOWER(?) LIMIT 1", [$name]);
        if ($r) return $r;
        $first = strtok(strtolower($name), ' ');
        if ($first === false || strlen($first) < 3) return null;
        return Db::one("SELECT * FROM suppliers WHERE LOWER(name) LIKE ? ORDER BY LENGTH(name) LIMIT 1", ['%' . $first . '%']);
    }
}
