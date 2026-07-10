<?php
declare(strict_types=1);

namespace App\Support;

/** Pure-PHP fuzzy string similarity for line matching. No extensions, no ML. */
final class Similarity
{
    /** Trigram (3-char shingle) Jaccard similarity, 0..1. */
    public static function trigram(string $a, string $b): float
    {
        $ta = self::shingles($a);
        $tb = self::shingles($b);
        if (!$ta || !$tb) return 0.0;
        $inter = count(array_intersect_key($ta, $tb));
        $union = count($ta + $tb);
        return $union ? $inter / $union : 0.0;
    }

    /** @return array<string,true> */
    private static function shingles(string $s): array
    {
        $s = ' ' . preg_replace('/\s+/', ' ', trim($s)) . ' ';
        $out = [];
        $len = strlen($s);
        for ($i = 0; $i + 3 <= $len; $i++) $out[substr($s, $i, 3)] = true;
        return $out;
    }

    /** Token-set Jaccard, weighting rarer/longer tokens a little higher. */
    public static function tokenOverlap(string $a, string $b): float
    {
        $ta = array_unique(array_filter(explode(' ', $a)));
        $tb = array_unique(array_filter(explode(' ', $b)));
        if (!$ta || !$tb) return 0.0;
        $wt = fn($t) => strlen($t) >= 4 ? 1.5 : 1.0;    // longer tokens carry more signal
        $setA = array_flip($ta); $setB = array_flip($tb);
        $interW = 0.0; $unionW = 0.0;
        foreach (array_unique(array_merge($ta, $tb)) as $t) {
            $in = isset($setA[$t]) && isset($setB[$t]);
            $unionW += $wt($t);
            if ($in) $interW += $wt($t);
        }
        return $unionW ? $interW / $unionW : 0.0;
    }

    /** Combined score 0..1 (trigram-weighted), on already-normalized strings. */
    public static function score(string $a, string $b): float
    {
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        return 0.6 * self::trigram($a, $b) + 0.4 * self::tokenOverlap($a, $b);
    }
}
