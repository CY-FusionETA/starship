<?php
declare(strict_types=1);

namespace App\Support;

/** Text / code normalization used by catalogue search, aliases and matching. */
final class Normalizer
{
    /** Lowercase, unify inch marks, collapse punctuation/space — for description matching. */
    public static function desc(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = str_replace(['"', '”', '“', '″'], ' inch ', $s);
        $s = preg_replace('/\b(od|id)\b/', ' ', $s);       // outer/inner diameter noise
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);        // punctuation -> space
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /** Build the catalogue search blob (brand + model + code + name + desc). */
    public static function searchBlob(array $item): string
    {
        $parts = [$item['brand'] ?? '', $item['model'] ?? '', $item['item_code'] ?? '', $item['name'] ?? '', $item['category'] ?? '', $item['description'] ?? ''];
        return self::desc(implode(' ', array_filter($parts)));
    }

    /** Normalize a project code: uppercase, strip spaces. */
    public static function projectCode(string $s): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($s)));
    }

    /** Normalize a PO number: strip spaces and slashes, uppercase (mirrors po_number_norm). */
    public static function poNumber(string $s): string
    {
        return strtoupper(str_replace([' ', '/'], '', trim($s)));
    }

    /** Canonicalize UOM synonyms. */
    public static function uom(?string $s): string
    {
        $s = strtolower(trim((string)$s));
        return match (true) {
            in_array($s, ['unit', 'units', 'pcs', 'pc', 'nos', 'no', 'each', 'ea'], true) => 'nos',
            in_array($s, ['ft', 'feet', 'foot'], true) => 'ft',
            in_array($s, ['m', 'meter', 'metre'], true) => 'm',
            $s === '' => '',
            default => $s,
        };
    }
}
