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
                // Both seeded from what the paperwork says arrived; the receiver
                // lowers 'accepted' if some of it is refused.
                'qty_delivered'             => $dl['ocr_qty'],
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

    /** Why goods were refused. Free text goes in reject_note alongside. */
    public const REASONS = [
        'damaged'        => 'Damaged in transit',
        'wrong_item'     => 'Wrong item sent',
        'short_supplied' => 'Short — fewer than the DO says',
        'quality'        => 'Quality / spec not acceptable',
        'expired'        => 'Expired or near expiry',
        'other'          => 'Other (see note)',
    ];

    /**
     * Commit human-confirmed matches.
     *
     * Per line the receiver states what ARRIVED and what was ACCEPTED; the gap
     * is rejected and needs a reason. Only accepted quantity is receipted, so
     * refused goods stay outstanding on the PO and the supplier still owes them.
     *
     * Over-delivery (accepted beyond the outstanding balance) is recorded but
     * held: the DO parks in 'exception' until a PM approves the overage.
     *
     * Returns [summary, error]. On error nothing is written — the whole thing
     * is one transaction, so a half-posted delivery can't exist.
     */
    public static function commit(int $doId, array $submitted): array
    {
        $do = DeliveryOrderRepo::find($doId);
        if (!$do) return ['', 'That delivery order no longer exists.'];

        // Re-confirming would post every receipt a second time. The UI hides the
        // form once confirmed, but a refresh or a replayed POST would not care.
        if (DeliveryOrderRepo::isConfirmed($doId)) {
            return ['', 'This delivery has already been confirmed — receipts were posted once.'];
        }

        $supplierId = (int)($do['supplier_id'] ?? 0);
        $doPoId     = (int)($do['purchase_order_id'] ?? 0);
        $lines      = DeliveryOrderRepo::lines($doId);

        // ---- validate everything up front: no partial posting ----
        $plan = [];
        foreach ($lines as $dl) {
            $s = $submitted[$dl['id']] ?? [];
            $label = $dl['ocr_description'] ?: ('line ' . $dl['line_no']);
            $poLineId = !empty($s['po_line_id']) ? (int)$s['po_line_id'] : null;

            if (!$poLineId) { $plan[] = ['dl' => $dl, 'unmatched' => true]; continue; }

            $ol = PurchaseOrderRepo::lineById($poLineId);
            if (!$ol) return ['', "“{$label}”: that PO line no longer exists."];
            // A PO line id from another PO would post a receipt against a PO this
            // delivery has nothing to do with.
            if ($doPoId && (int)$ol['purchase_order_id'] !== $doPoId) {
                return ['', "“{$label}”: that line belongs to a different purchase order."];
            }

            $delivered = self::num($s['qty_delivered'] ?? '', (float)($dl['ocr_qty'] ?? 0));
            $accepted  = self::num($s['qty'] ?? '', $delivered);
            if ($delivered === null) return ['', "“{$label}”: delivered quantity must be a number."];
            if ($accepted === null)  return ['', "“{$label}”: accepted quantity must be a number."];
            // A negative posts backwards and silently reduces the PO's receipts.
            if ($delivered < 0 || $accepted < 0) return ['', "“{$label}”: quantities cannot be negative."];
            if ($accepted > $delivered + 1e-6) {
                return ['', "“{$label}”: you can't accept more than the {$delivered} delivered."];
            }

            $rejected = round($delivered - $accepted, 6);
            $reason   = trim((string)($s['reject_reason'] ?? ''));
            if ($rejected > 1e-6 && !isset(self::REASONS[$reason])) {
                return ['', "“{$label}”: say why {$rejected} were not accepted."];
            }
            if ($rejected <= 1e-6) { $reason = ''; }

            $plan[] = [
                'dl' => $dl, 'unmatched' => false, 'po_line' => $ol,
                'delivered' => $delivered, 'accepted' => $accepted, 'rejected' => max(0.0, $rejected),
                'reason' => $reason ?: null, 'note' => trim((string)($s['reject_note'] ?? '')) ?: null,
            ];
        }

        return Db::tx(function () use ($doId, $do, $supplierId, $plan) {
            $touchedPo = []; $anyUnmatched = false; $anyOver = false; $anyRejected = false;

            foreach ($plan as $row) {
                $dl = $row['dl'];
                if ($row['unmatched']) {
                    DeliveryOrderRepo::updateLine((int)$dl['id'], [
                        'is_confirmed' => 1, 'verdict' => 'unmatched',
                        'matched_po_line_id' => null, 'qty_accepted' => null, 'match_method' => 'manual',
                        'qty_delivered' => (float)($dl['ocr_qty'] ?? 0), 'qty_rejected' => 0,
                    ]);
                    $anyUnmatched = true;
                    continue;
                }

                $poLineId = (int)$row['po_line']['id'];
                // Only the accepted quantity is a receipt. Rejected goods were
                // handed back, so the PO line stays short and open.
                Db::q("UPDATE po_lines SET qty_received = qty_received + ? WHERE id = ?", [$row['accepted'], $poLineId]);
                $ol2 = PurchaseOrderRepo::lineById($poLineId);
                $recv = (float)$ol2['qty_received']; $ord = (float)$ol2['qty_ordered'];
                Db::update('po_lines', $poLineId, ['line_status' => self::lineStatus($recv, $ord)]);

                $over = $recv > $ord + 1e-6;
                if ($over) $anyOver = true;
                if ($row['rejected'] > 1e-6) $anyRejected = true;

                $catId = $ol2['catalogue_item_id'] ? (int)$ol2['catalogue_item_id'] : null;
                DeliveryOrderRepo::updateLine((int)$dl['id'], [
                    'is_confirmed'       => 1,
                    'matched_po_line_id' => $poLineId,
                    'matched_catalogue_item_id' => $catId,
                    'qty_delivered'      => $row['delivered'],
                    'qty_accepted'       => $row['accepted'],
                    'qty_rejected'       => $row['rejected'],
                    'reject_reason'      => $row['reason'],
                    'reject_note'        => $row['note'],
                    'verdict'            => $row['rejected'] > 1e-6 ? 'rejected' : self::verdictForTotal($recv, $ord),
                    'match_method'       => $dl['match_method'] ?: 'manual',
                ]);
                if ($catId && $supplierId) {
                    AliasRepo::learn($supplierId, $dl['ocr_supplier_code'] ?: null, $dl['ocr_description'], $catId);
                }
                $touchedPo[(int)$row['po_line']['purchase_order_id']] = true;
            }

            foreach (array_keys($touchedPo) as $poId) PurchaseOrderRepo::recomputeStatus($poId);

            $poId = array_key_first($touchedPo) ?: ($do['purchase_order_id'] ?? null);
            $summary = self::summaryFor($poId);
            $noSig = ($do['signature_present'] !== null && (int)$do['signature_present'] === 0);
            // Over-delivery is held for sign-off rather than absorbed silently.
            $status = ($anyUnmatched || $noSig || $anyOver) ? 'exception' : 'matched';
            DeliveryOrderRepo::setHeader($doId, [
                'status'        => $status,
                'match_summary' => $summary,
                'reviewed_at'   => date('Y-m-d H:i:s'),
                'reviewed_by'   => Auth::id(),
            ]);
            AuditRepo::log('delivery_order', $doId, 'confirm_match', [
                'summary' => $summary, 'unmatched' => $anyUnmatched,
                'over' => $anyOver, 'rejected' => $anyRejected,
            ]);
            return [$summary, null];
        });
    }

    /** Parse a posted quantity: '' → $default, non-numeric → null (an error, not 0). */
    private static function num($raw, float $default): ?float
    {
        $raw = trim((string)$raw);
        if ($raw === '') return $default;
        if (!is_numeric($raw)) return null;
        return (float)$raw;
    }

    /**
     * Sign off an over-delivery: the receipt already posted, this records that a
     * PM accepted the overage and clears the DO's exception.
     */
    public static function approveOverDelivery(int $doId, string $note = ''): ?string
    {
        $do = DeliveryOrderRepo::find($doId);
        if (!$do) return 'That delivery order no longer exists.';
        if (!DeliveryOrderRepo::hasOverDelivery($doId)) return 'Nothing is over-delivered on this delivery order.';

        $unmatched = DeliveryOrderRepo::hasUnmatchedLines($doId);
        $noSig = ($do['signature_present'] !== null && (int)$do['signature_present'] === 0);
        DeliveryOrderRepo::setHeader($doId, [
            'over_approved_by'   => Auth::id(),
            'over_approved_at'   => date('Y-m-d H:i:s'),
            'over_approval_note' => trim($note) ?: null,
            // Only clear the exception if the overage was the only thing wrong.
            'status'             => ($unmatched || $noSig) ? 'exception' : 'matched',
        ]);
        AuditRepo::log('delivery_order', $doId, 'approve_over_delivery', ['note' => $note]);
        return null;
    }

    /**
     * The one definition of a PO line's status. Public because DO deletion
     * reverses receipts and must land on exactly the same answer — it used to
     * keep its own copy, and the two had already drifted apart.
     */
    public static function lineStatus(float $recv, float $ordered): string
    {
        if ($recv > $ordered + 1e-6) return 'over_received';
        // A zero-qty line isn't "fully received" — guard before the equality test.
        if ($ordered <= 0) return 'open';
        if (abs($recv - $ordered) < 1e-6) return 'fully_received';
        return $recv > 0 ? 'partially_received' : 'open';
    }

    private static function verdictForTotal(float $recv, float $ordered): string
    {
        if ($recv > $ordered + 1e-6) return 'over';
        if (abs($recv - $ordered) < 1e-6) return 'fully_received';
        return 'partially_received';
    }

    /**
     * Human-readable receipt summary for a PO (shown on the DO + PO screens).
     *
     * Counts backorder and overage separately: summing them into one number let
     * an over-delivered line cancel out a short one, so "28/30 received, 5 on
     * backorder" could describe a PO that was both over and short at once.
     */
    public static function summaryFor(?int $poId): string
    {
        if (!$poId) return 'No PO matched';
        $po = PurchaseOrderRepo::find($poId);
        $recv = 0.0; $ord = 0.0; $back = 0.0; $over = 0.0;
        foreach (PurchaseOrderRepo::lines($poId) as $l) {
            $r = (float)$l['qty_received']; $o = (float)$l['qty_ordered'];
            $recv += $r; $ord += $o;
            if ($o - $r > 0) $back += $o - $r;
            if ($r - $o > 0) $over += $r - $o;
        }
        $fmt = fn($n) => rtrim(rtrim(number_format($n, 2), '0'), '.');
        $bits = [];
        if ($back > 0) $bits[] = $fmt($back) . ' on backorder';
        if ($over > 0) $bits[] = $fmt($over) . ' over-delivered';
        return "PO {$po['po_number']}: " . $fmt($recv) . '/' . $fmt($ord) . ' received'
             . ($bits ? ', ' . implode(', ', $bits) : '');
    }
}
