<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;

/** Google Gemini vision OCR for delivery-order images. Plain cURL, forced JSON. */
final class GeminiOcrService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    // Primary model for clean printed DOs; escalate to the stronger one on low confidence.
    // (gemini-2.5-flash is the most consistently available tier on the current key.)
    private const MODEL_CHEAP  = 'gemini-2.5-flash';
    private const MODEL_STRONG = 'gemini-3-flash-preview';

    private const PROMPT =
        "You are reading a Malaysian supplier DELIVERY ORDER (DO) that has been photographed or scanned " .
        "(often via CamScanner, sometimes with handwriting and a signature/company chop). " .
        "Transcribe it faithfully into the given JSON schema. Rules: " .
        "transcribe descriptions and codes VERBATIM — do NOT normalize units, spelling or part codes. " .
        "po_reference = the customer/Globe PO number if present (may look like '130536' or '1/157601R'); " .
        "do NOT confuse it with the supplier's own DO number or sales-order number. " .
        "project_code = any project/site code such as '24B-135494' if shown. " .
        "signature_present = true only if a signature, chop or 'received by' acceptance mark is visible. " .
        "Put any handwritten margin notes (delivery instructions, contact numbers, balance notes) in handwritten_notes. " .
        "For each line item give description, supplier_code (if any), qty (number), uom, and line_confidence 0-100. " .
        "Leave any unknown string field as an empty string. overall_confidence is your 0-100 legibility self-rating.";

    private static function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'supplier_name'     => ['type' => 'STRING'],
                'do_number'         => ['type' => 'STRING'],
                'po_reference'      => ['type' => 'STRING'],
                'project_code'      => ['type' => 'STRING'],
                'delivery_date'     => ['type' => 'STRING'],
                'signature_present' => ['type' => 'BOOLEAN'],
                'handwritten_notes' => ['type' => 'STRING'],
                'line_items'        => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'description'     => ['type' => 'STRING'],
                            'supplier_code'   => ['type' => 'STRING'],
                            'qty'             => ['type' => 'NUMBER'],
                            'uom'             => ['type' => 'STRING'],
                            'line_confidence' => ['type' => 'NUMBER'],
                        ],
                        'required' => ['description', 'qty'],
                    ],
                ],
                'overall_confidence' => ['type' => 'NUMBER'],
            ],
            'required' => ['line_items', 'overall_confidence'],
        ];
    }

    /**
     * Extract structured data from a DO image. Cheap model first, escalate on low confidence.
     * @return array{data:array,model:string,confidence:float,runs:array}
     */
    public static function extract(string $absImagePath): array
    {
        [$b64, $mime] = self::prepareImage($absImagePath);

        $cheap  = self::MODEL_CHEAP;
        $strong = self::MODEL_STRONG;
        $threshold = (float)cfg('gemini.escalate_below', 75);

        $runs = [];
        $accepted = null;

        // Primary attempt; if the model is transiently unavailable, fall back to the other.
        try {
            $accepted = self::callModel($cheap, $b64, $mime);
            $runs[] = $accepted;
        } catch (\Throwable $e) {
            $accepted = self::callModel($strong, $b64, $mime);   // fallback (may throw -> caller handles)
            $accepted['escalated'] = true;
            $runs[] = $accepted;
        }

        // Escalate to the stronger model on low confidence / missing key fields.
        $d = $accepted['data'];
        $need = $accepted['model'] === $cheap && (
            ((float)($d['overall_confidence'] ?? 0) < $threshold)
            || empty($d['line_items'])
            || (empty($d['po_reference']) && empty($d['project_code']))
        );
        if ($need) {
            try {
                $r2 = self::callModel($strong, $b64, $mime);
                $r2['escalated'] = true;
                $runs[] = $r2;
                if ((float)($r2['data']['overall_confidence'] ?? 0) >= (float)($d['overall_confidence'] ?? 0)) {
                    $accepted = $r2;
                }
            } catch (\Throwable $e) {
                // keep the primary result if escalation is unavailable
            }
        }

        return [
            'data'       => $accepted['data'],
            'model'      => $accepted['model'],
            'confidence' => (float)($accepted['data']['overall_confidence'] ?? 0),
            'runs'       => $runs,
        ];
    }

    /** One model call. @return array{model:string,data:array,latency_ms:int,prompt_tokens:int,output_tokens:int,escalated:bool} */
    private static function callModel(string $model, string $b64, string $mime): array
    {
        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => self::PROMPT],
                    ['inline_data' => ['mime_type' => $mime, 'data' => $b64]],
                ],
            ]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'response_schema'    => self::schema(),
                'temperature'        => 0,
            ],
        ];
        $url = sprintf(self::ENDPOINT, $model);
        $payload = json_encode($body);
        $t0  = microtime(true);
        $resp = false; $code = 0; $err = '';
        // Retry transient 5xx (model "high demand") a couple of times.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . cfg('gemini.api_key')],
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 90,
            ]);
            if ($proxy = cfg('gemini.proxy')) curl_setopt($ch, CURLOPT_PROXY, (string)$proxy);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($code === 200) break;
            if (!in_array($code, [500, 502, 503, 504], true)) break;   // only retry transient
            usleep(($attempt * 700) * 1000);
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if ($resp === false || $code !== 200) {
            throw new \RuntimeException("Gemini {$model} HTTP {$code}: " . ($err ?: substr((string)$resp, 0, 300)));
        }
        $j = json_decode($resp, true);
        $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $data = json_decode($text, true);
        if (!is_array($data)) throw new \RuntimeException("Gemini {$model}: unparseable JSON output");

        return [
            'model'         => $model,
            'data'          => $data,
            'latency_ms'    => $ms,
            'prompt_tokens' => (int)($j['usageMetadata']['promptTokenCount'] ?? 0),
            'output_tokens' => (int)($j['usageMetadata']['candidatesTokenCount'] ?? 0),
            'escalated'     => false,
        ];
    }

    /** Downscale raster images with GD; pass PDFs through. @return array{0:string,1:string} [base64, mime] */
    private static function prepareImage(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $raw = file_get_contents($path);
        if ($ext === 'pdf') return [base64_encode($raw), 'application/pdf'];
        if (!function_exists('imagecreatefromstring')) return [base64_encode($raw), 'image/jpeg'];

        $img = @imagecreatefromstring($raw);
        if (!$img) return [base64_encode($raw), 'image/jpeg'];
        $w = imagesx($img); $h = imagesy($img); $max = 1600;
        if (max($w, $h) > $max) {
            $s = $max / max($w, $h);
            $nw = (int)($w * $s); $nh = (int)($h * $s);
            $r = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($r, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img); $img = $r;
        }
        ob_start(); imagejpeg($img, null, 80); $out = ob_get_clean();
        imagedestroy($img);
        return [base64_encode($out), 'image/jpeg'];
    }

    /** Persist run rows (cost/observability) against a DO. */
    public static function logRuns(int $doId, array $runs): void
    {
        $attempt = 0;
        foreach ($runs as $r) {
            $attempt++;
            Db::insert('gemini_ocr_runs', [
                'delivery_order_id' => $doId,
                'model'         => $r['model'],
                'attempt'       => $attempt,
                'confidence'    => $r['data']['overall_confidence'] ?? null,
                'latency_ms'    => $r['latency_ms'] ?? null,
                'prompt_tokens' => $r['prompt_tokens'] ?? null,
                'output_tokens' => $r['output_tokens'] ?? null,
                'escalated'     => !empty($r['escalated']) ? 1 : 0,
                'raw_response'  => json_encode($r['data'], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
}
