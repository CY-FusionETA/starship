<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\Auth;
use App\Repo\DeliveryOrderRepo;
use App\Repo\PurchaseOrderRepo;
use App\Repo\AliasRepo;
use App\Repo\AuditRepo;
use App\Support\Normalizer;
use App\Support\Similarity;

/** 3-way matching: DO -> PO (header keying) + line fuzzy match + verdicts. */
final class MatchingService
{
    private const SUGGEST_THRESHOLD = 0.55;   // min combined similarity to suggest a line

    /** Resolve a PO from po_reference + project code (+ supplier tiebreak), unless already linked. */
    public static function resolvePo(array $do): ?int
    {
        if (!empty($do['purchase_order_id'])) return (int)$do['purchase_order_id'];

        $normPo   = !empty($do['po_reference_raw']) ? Normalizer::poNumber($do['po_reference_raw']) : '';
        $normProj = !empty($do['project_code_raw']) ? Normalizer::projectCode($do['project_code_raw']) : '';
        $open = "status IN ('issued','partially_received')";

        $cands = [];
        if ($normPo !== '') {
            $cands = Db::all("SELECT * FROM purchase_orders WHERE po_number_norm = ? AND {$open}", [$normPo]);
        }
        // Project code as fallback / disambiguator.
        if (count($cands) !== 1 && $normProj !== '') {
            $proj = Db::one("SELECT id FROM projects WHERE project_code_norm = ?", [$normProj]);
            if ($proj) {
                $byProj = Db::all("SELECT * FROM purchase_orders WHERE project_id = ? AND {$open}", [(int)$proj['id']]);
                if ($cands) {
                    $keep = array_flip(array_column($byProj, 'id'));
                    $cands = array_values(array_filter($cands, fn($c) => isset($keep[$c['id']])));
                } else {
                    $cands = $byProj;
                }
            }
        }
        // Supplier narrows remaining ties.
        if (count($cands) > 1 && !empty($do['supplier_id'])) {
            $cands = array_values(array_filter($cands, fn($c) => (int)$c['supplier_id'] === (int)$do['supplier_id']));
        }
        return count($cands) === 1 ? (int)$cands[0]['id'] : null;
    }

    /** Fill suggested matches + verdicts on each DO line (non-committal). */
    public static function suggest(int $doId): void
    {
        $do = DeliveryOrderRepo::find($doId);
        $poId = self::resolvePo($do);
        if ($poId) {
            $po = PurchaseOrderRepo::find($poId);
            $upd = ['purchase_order_id' => $poId];
            if (empty($do['project_id']))  $upd['project_id']  = (int)$po['project_id'];
            if (empty($do['supplier_id'])) $upd['supplier_id'] = (int)$po['supplier_id'];
            DeliveryOrderRepo::setHeader($doId, $upd);
            $do = array_merge($do, $upd);
        }

        $supplierId = (int)($do['supplier_id'] ?? 0);
        $openLines  = $poId ? PurchaseOrderRepo::openLines($poId) : [];

        foreach (DeliveryOrderRepo::lines($doId) as $dl) {
            $m = self::matchLine($dl, $supplierId, $openLines);
            $qty = (float)($dl['ocr_qty'] ?? 0);
            DeliveryOrderRepo::updateLine((int)$dl['id'], [
                'matched_po_line_id'        => $m['po_line_id'],
                'matched_catalogue_item_id' => $m['catalogue_item_id'],
                'match_method'              => $m['method'],
                'match_score'               => $m['score'],
                'qty_accepted'              => $dl['ocr_qty'],
                'verdict'                   => $m['po_line_id'] ? self::verdictFor((int)$m['po_line_id'], $qty) : 'unmatched',
            ]);
        }
        DeliveryOrderRepo::setHeader($doId, ['status' => $poId ? 'needs_review' : 'exception']);
    }

    /** Cascade-match one DO line to an open PO line. Cheapest, highest-trust first. */
    private static function matchLine(array $dl, int $supplierId, array $openLines): array
    {
        $none = ['po_line_id' => null, 'catalogue_item_id' => null, 'method' => 'none', 'score' => null];
        if (!$openLines) return $none;

        $descNorm = Normalizer::desc($dl['ocr_description']);
        $byItem = [];
        foreach ($openLines as $ol) if ($ol['catalogue_item_id']) $byItem[(int)$ol['catalogue_item_id']][] = $ol;

        // Tier 1 — supplier part code (highest trust)
        if (!empty($dl['ocr_supplier_code']) && $supplierId) {
            $al = AliasRepo::byPartCode($supplierId, $dl['ocr_supplier_code']);
            if ($al && !empty($byItem[(int)$al['catalogue_item_id']])) {
                $ol = $byItem[(int)$al['catalogue_item_id']][0];
                return ['po_line_id' => (int)$ol['id'], 'catalogue_item_id' => (int)$al['catalogue_item_id'], 'method' => 'supplier_code', 'score' => 100.0];
            }
        }
        // Tier 2 — learned alias by normalized description
        if ($supplierId) {
            $al = AliasRepo::byDescNorm($supplierId, $descNorm);
            if ($al && !empty($byItem[(int)$al['catalogue_item_id']])) {
                $ol = $byItem[(int)$al['catalogue_item_id']][0];
                return ['po_line_id' => (int)$ol['id'], 'catalogue_item_id' => (int)$al['catalogue_item_id'], 'method' => 'alias_exact', 'score' => 98.0];
            }
        }
        // Tier 3 — fuzzy over each open PO line (description + catalogue name)
        $best = null; $bestScore = 0.0;
        foreach ($openLines as $ol) {
            $cands = [Normalizer::desc($ol['description'])];
            if (!empty($ol['catalogue_name'])) $cands[] = Normalizer::desc($ol['catalogue_name']);
            $s = 0.0;
            foreach ($cands as $c) $s = max($s, Similarity::score($descNorm, $c));
            if ($s > $bestScore) { $bestScore = $s; $best = $ol; }
        }
        if ($best && $bestScore >= self::SUGGEST_THRESHOLD) {
            return ['po_line_id' => (int)$best['id'], 'catalogue_item_id' => $best['catalogue_item_id'] ? (int)$best['catalogue_item_id'] : null, 'method' => 'trigram', 'score' => round($bestScore * 100, 2)];
        }
        return $none;
    }

    /** Hypothetical verdict for a line if $qty were received (value-independent). */
    private static function verdictFor(int $poLineId, float $qty): string
    {
        $ol = PurchaseOrderRepo::lineById($poLineId);
        if (!$ol) return 'unmatched';
        return self::verdictForTotal((float)$ol['qty_received'] + $qty, (float)$ol['qty_ordered']);
    }

    /** Commit human-confirmed matches: cumulative receipts, verdicts, alias learning, rollups. */
    public static function commit(int $doId, array $submitted): string
    {
        $do = DeliveryOrderRepo::find($doId);
        $supplierId = (int)($do['supplier_id'] ?? 0);

        return Db::tx(function () use ($doId, $do, $supplierId, $submitted) {
            $touchedPo = []; $anyUnmatched = false;

            foreach (DeliveryOrderRepo::lines($doId) as $dl) {
                $s = $submitted[$dl['id']] ?? [];
                $poLineId = !empty($s['po_line_id']) ? (int)$s['po_line_id'] : null;
                $qty = (($s['qty'] ?? '') === '') ? (float)($dl['ocr_qty'] ?? 0) : (float)$s['qty'];

                if (!$poLineId) {
                    DeliveryOrderRepo::updateLine((int)$dl['id'], [
                        'is_confirmed' => 1, 'verdict' => 'unmatched',
                        'matched_po_line_id' => null, 'qty_accepted' => null, 'match_method' => 'manual',
                    ]);
                    $anyUnmatched = true;
                    continue;
                }
                $ol = PurchaseOrderRepo::lineById($poLineId);
                Db::q("UPDATE po_lines SET qty_received = qty_received + ? WHERE id = ?", [$qty, $poLineId]);
                $ol2 = PurchaseOrderRepo::lineById($poLineId);
                Db::update('po_lines', $poLineId, ['line_status' => self::lineStatus((float)$ol2['qty_received'], (float)$ol2['qty_ordered'])]);
                $catId = $ol2['catalogue_item_id'] ? (int)$ol2['catalogue_item_id'] : null;
                DeliveryOrderRepo::updateLine((int)$dl['id'], [
                    'is_confirmed' => 1, 'matched_po_line_id' => $poLineId, 'matched_catalogue_item_id' => $catId,
                    'qty_accepted' => $qty, 'verdict' => self::verdictForTotal((float)$ol2['qty_received'], (float)$ol2['qty_ordered']),
                    'match_method' => $dl['match_method'] ?: 'manual',
                ]);
                if ($catId && $supplierId) {
                    AliasRepo::learn($supplierId, $dl['ocr_supplier_code'] ?: null, $dl['ocr_description'], $catId);
                }
                $touchedPo[(int)$ol['purchase_order_id']] = true;
            }

            foreach (array_keys($touchedPo) as $poId) PurchaseOrderRepo::recomputeStatus($poId);

            $poId = array_key_first($touchedPo) ?: ($do['purchase_order_id'] ?? null);
            $summary = self::summaryFor($poId);
            $noSig = ($do['signature_present'] !== null && (int)$do['signature_present'] === 0);
            DeliveryOrderRepo::setHeader($doId, [
                'status'       => ($anyUnmatched || $noSig) ? 'exception' : 'matched',
                'match_summary' => $summary,
                'reviewed_at'  => date('Y-m-d H:i:s'),
                'reviewed_by'  => Auth::id(),
            ]);
            AuditRepo::log('delivery_order', $doId, 'confirm_match', ['summary' => $summary, 'unmatched' => $anyUnmatched]);
            return $summary;
        });
    }

    private static function lineStatus(float $recv, float $ordered): string
    {
        if ($recv > $ordered + 1e-6) return 'over_received';
        if (abs($recv - $ordered) < 1e-6) return 'fully_received';
        return $recv > 0 ? 'partially_received' : 'open';
    }

    private static function verdictForTotal(float $recv, float $ordered): string
    {
        if ($recv > $ordered + 1e-6) return 'over';
        if (abs($recv - $ordered) < 1e-6) return 'fully_received';
        return 'partially_received';
    }

    /** Human-readable receipt summary for a PO (fed back to the DO + WhatsApp later). */
    public static function summaryFor(?int $poId): string
    {
        if (!$poId) return 'No PO matched';
        $po = PurchaseOrderRepo::find($poId);
        $recv = 0.0; $ord = 0.0; $back = 0.0;
        foreach (PurchaseOrderRepo::lines($poId) as $l) {
            $recv += (float)$l['qty_received']; $ord += (float)$l['qty_ordered'];
            $b = (float)$l['qty_ordered'] - (float)$l['qty_received'];
            if ($b > 0) $back += $b;
        }
        $fmt = fn($n) => rtrim(rtrim(number_format($n, 2), '0'), '.');
        return "PO {$po['po_number']}: " . $fmt($recv) . "/" . $fmt($ord) . " received" . ($back > 0 ? ", " . $fmt($back) . " on backorder" : "");
    }
}
