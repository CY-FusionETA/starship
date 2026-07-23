<?php
declare(strict_types=1);

namespace App\Service\Wazzup;

use App\Db;
use App\Settings;
use App\Storage;
use App\Repo\DeliveryOrderRepo;
use App\Repo\SupplierRepo;
use App\Repo\WaSenderRepo;
use App\Service\GeminiOcrService;
use App\Service\MatchingService;

/**
 * Turns inbound Wazzup WhatsApp messages into Delivery Orders.
 * Flow: allowlisted sender photographs a DO/invoice -> we OCR it (Gemini vision,
 * incl. handwriting) -> save a DO -> WhatsApp back the extracted details so the
 * sender sees it arrived and the AI read it correctly.
 */
final class WazzupIntake
{
    /** Secret appended to the webhook URL (?token=) so only Wazzup can post to us. */
    public static function webhookToken(): string
    {
        $t = trim((string)Settings::raw('wazzup.webhook_token', ''));
        if ($t === '') { $t = bin2hex(random_bytes(16)); Settings::set('wazzup.webhook_token', $t); }
        return $t;
    }

    public static function webhookUrl(): string
    {
        $base = rtrim((string)cfg('app.base_url', ''), '/');
        return $base . '/api/wazzup_webhook.php?token=' . self::webhookToken();
    }

    /**
     * Handle a decoded Wazzup webhook payload. Processes each inbound (non-echo)
     * message; images/documents become DOs. Returns a per-message result list.
     */
    public static function handle(array $payload): array
    {
        $results = [];
        foreach (($payload['messages'] ?? []) as $m) {
            if (!empty($m['isEcho'])) continue;                 // our own outbound, echoed back
            $sender = (string)($m['chatId'] ?? $m['contact']['phone'] ?? '');
            if ($sender === '') continue;

            if (!WaSenderRepo::isAllowed($sender)) {
                self::log('ignored_sender', $sender, $m);
                $results[] = ['sender' => $sender, 'status' => 'ignored_sender'];
                continue;
            }
            WaSenderRepo::touch($sender);

            // Idempotency: Wazzup may retry a delivery — don't double-ingest.
            $mid = (string)($m['messageId'] ?? '');
            if ($mid !== '' && self::alreadyIngested($mid)) {
                $results[] = ['sender' => $sender, 'status' => 'duplicate'];
                continue;
            }

            $mediaUrl = $m['contentUri'] ?? $m['content']['uri'] ?? null;
            $type = strtolower((string)($m['type'] ?? ''));
            $hasMedia = $mediaUrl && in_array($type, ['image', 'document', 'video', ''], true) && $type !== 'text';

            try {
                if ($hasMedia) {
                    $r = self::ingestFromUrl($sender, (string)$mediaUrl, (string)($m['messageId'] ?? ''));
                    $results[] = ['sender' => $sender, 'status' => 'ingested', 'do_id' => $r['do_id']];
                } else {
                    // Text-only: nudge them to send a photo.
                    WazzupClient::sendText($sender,
                        "👋 Send a *photo of the Delivery Order or Invoice* here and I'll log it into Starship and read the details back to you.");
                    $results[] = ['sender' => $sender, 'status' => 'text_hint'];
                }
            } catch (\Throwable $e) {
                self::log('error', $sender, $m, $e->getMessage());
                WazzupClient::sendText($sender, "⚠️ Sorry, I couldn't process that document. Please resend a clearer photo, or contact the office.");
                $results[] = ['sender' => $sender, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /** Download media, then ingest. */
    public static function ingestFromUrl(string $sender, string $url, string $messageId): array
    {
        [$bytes, $ext] = WazzupClient::downloadMedia($url);
        return self::ingest($sender, $bytes, $ext, $messageId);
    }

    /**
     * Core ingest: store image, OCR, create the DO, reply with the details.
     * @return array{do_id:int, sent:array}
     */
    public static function ingest(string $sender, string $bytes, string $ext, string $messageId): array
    {
        $path = Storage::saveImage($bytes, $ext);

        $ocr = null;
        try { $ocr = GeminiOcrService::extract(Storage::absPath($path)); }
        catch (\Throwable $e) { error_log('Wazzup OCR failed: ' . $e->getMessage()); }

        $d = $ocr['data'] ?? [];
        $header = [
            'do_number'         => $d['do_number'] ?? '',
            'po_reference_raw'  => $d['po_reference'] ?? '',
            'project_code_raw'  => $d['project_code'] ?? '',
            'delivery_date'     => $d['delivery_date'] ?? '',
            'handwritten_notes' => $d['handwritten_notes'] ?? '',
            'signature_present' => array_key_exists('signature_present', $d) ? (!empty($d['signature_present']) ? 1 : 0) : '',
            'image_path'        => $path,
        ];
        if (!empty($d['supplier_name']) && ($sup = SupplierRepo::matchByName((string)$d['supplier_name']))) {
            $header['supplier_id'] = $sup['id'];
        }

        $lines = [];
        foreach (($d['line_items'] ?? []) as $li) {
            $lines[] = [
                'ocr_description'   => $li['description'] ?? '',
                'ocr_supplier_code' => $li['supplier_code'] ?? '',
                'ocr_qty'           => $li['qty'] ?? '',
                'ocr_uom'           => $li['uom'] ?? '',
            ];
        }

        // Training sample DO? Route this one message into the isolated demo DB
        // so a live-pipeline test never clutters real records. The reply itself
        // is sent afterwards on the real DB (that is where Wazzup creds live).
        $isDemo = \App\Tour::isDemoOcr($d) && is_file(\App\Db::demoPath());
        if ($isDemo) \App\Db::useDemo(true);
        try {
            $doId = DeliveryOrderRepo::create($header, $lines);
            DeliveryOrderRepo::setHeader($doId, array_filter([
                'source_channel'    => $isDemo ? 'wazzup_demo' : 'wazzup',
                'sender_wa_e164'    => WaSenderRepo::normalize($sender),
                'wazzup_message_id' => $messageId ?: null,
                'ocr_model'         => $ocr['model'] ?? null,
                'ocr_confidence'    => $ocr['confidence'] ?? null,
                'ocr_raw_json'      => $ocr ? json_encode($ocr['data'], JSON_UNESCAPED_UNICODE) : null,
            ], fn($v) => $v !== null));

            if ($ocr) GeminiOcrService::logRuns($doId, $ocr['runs']);
            try { MatchingService::suggest($doId); } catch (\Throwable $e) { /* matching is best-effort */ }

            $text = self::formatConfirmation($doId, $d, $ocr !== null);
        } finally {
            if ($isDemo) \App\Db::useDemo(false);
        }

        $sent = WazzupClient::sendText($sender, $text);
        self::log('ingested', $sender, ['messageId' => $messageId], null, $doId);

        return ['do_id' => $doId, 'sent' => $sent];
    }

    /** Build the WhatsApp receipt listing everything the AI captured. */
    public static function formatConfirmation(int $doId, array $ocr, bool $ocrRan): string
    {
        $do   = DeliveryOrderRepo::find($doId);
        $rows = DeliveryOrderRepo::lines($doId);
        $base = rtrim((string)cfg('app.base_url', ''), '/');
        $val  = fn($v) => trim((string)$v) !== '' ? trim((string)$v) : '—';

        if (!$ocrRan) {
            return "📥 Received your document (Starship #{$doId}).\n" .
                   "I couldn't auto-read it this time — a team member will review it manually.";
        }

        $supplier = $do['supplier_name'] ?: ('“' . ($ocr['supplier_name'] ?? 'unknown') . '” (not yet in system)');
        $sig  = array_key_exists('signature_present', $ocr) ? (!empty($ocr['signature_present']) ? '✔ present' : '✖ none') : '—';
        $conf = isset($ocr['overall_confidence']) ? round((float)$ocr['overall_confidence']) . '%' : '—';

        $L = [];
        $L[] = "✅ *Received — Delivery Order / Invoice*";
        $L[] = "DO/Inv No: *" . $val($ocr['do_number'] ?? '') . "*";
        $L[] = "Supplier: " . $supplier;
        $L[] = "PO Ref: " . $val($ocr['po_reference'] ?? '');
        $L[] = "Project: " . $val($ocr['project_code'] ?? '');
        $L[] = "Date: " . $val($ocr['delivery_date'] ?? '');
        $L[] = "Signature/Chop: " . $sig;
        $L[] = "AI confidence: " . $conf;

        $L[] = "";
        $L[] = "*Items (" . count($rows) . "):*";
        $i = 0;
        foreach ($rows as $r) {
            $i++;
            $qty  = rtrim(rtrim(number_format((float)($r['ocr_qty'] ?? 0), 2), '0'), '.');
            $code = !empty($r['ocr_supplier_code']) ? ' [' . $r['ocr_supplier_code'] . ']' : '';
            $L[] = "{$i}. " . ($r['ocr_description'] ?: '—') . " — {$qty} " . ($r['ocr_uom'] ?: '') . $code;
        }
        if ($i === 0) $L[] = "_(no line items detected)_";

        if (!empty($ocr['handwritten_notes'])) {
            $L[] = "";
            $L[] = "📝 *Handwritten notes:* " . $ocr['handwritten_notes'];
        }

        $L[] = "";
        $L[] = "🔖 Saved as DO #{$doId} — {$base}/delivery-orders/{$doId}";
        return implode("\n", $L);
    }

    private static function alreadyIngested(string $messageId): bool
    {
        return (bool)Db::scalar(
            "SELECT 1 FROM webhook_events WHERE provider='wazzup' AND external_id=? AND status='ingested' LIMIT 1",
            [$messageId]
        );
    }

    private static function log(string $status, string $sender, array $msg, ?string $error = null, ?int $doId = null): void
    {
        try {
            Db::insert('webhook_events', [
                'provider'          => 'wazzup',
                'external_id'       => $msg['messageId'] ?? null,
                'signature_ok'      => 1,
                'payload_json'      => json_encode(['sender' => $sender, 'msg' => $msg], JSON_UNESCAPED_UNICODE),
                'status'            => $status,
                'error_text'        => $error,
                'delivery_order_id' => $doId,
                'processed_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* logging must never break intake */ }
    }
}
