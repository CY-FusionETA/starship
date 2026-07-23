<?php
/**
 * Wazzup fan-out forwarder for the FusionETA WhatsApp line (60102300975).
 *
 * Wazzup allows only ONE webhook per account. This endpoint takes that one
 * webhook and relays every payload to BOTH downstream apps, so they keep
 * working side by side:
 *   - WazzOCR : https://wazzocr.fusioneta.com.my/webhook.php
 *   - Starship: https://starship.fusioneta.com.my/api/wazzup_webhook.php
 *
 * Register THIS url as the Wazzup webhook (Integrations -> Webhooks):
 *   https://starship.fusioneta.com.my/wa_forward.php?t=09722c5868e36068d5908808796cab01
 */

// --- guard: only accept calls carrying our forward token ---
$FWD_TOKEN = '09722c5868e36068d5908808796cab01';
if (!hash_equals($FWD_TOKEN, (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';

// Answer Wazzup immediately so its connectivity test + deliveries never time out.
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

if ($raw === '') { exit; }

// Relay to both downstreams in parallel; failures are ignored (each app is
// responsible for its own processing).
$targets = [
    'https://wazzocr.fusioneta.com.my/webhook.php',
    'https://starship.fusioneta.com.my/api/wazzup_webhook.php?token=2cc7b32ac62123145db8b087ccb0192c',
];

$mh = curl_multi_init();
$handles = [];
foreach ($targets as $t) {
    $ch = curl_init($t);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $raw,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) { curl_multi_select($mh, 1.0); }
} while ($running && $status === CURLM_OK);
foreach ($handles as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
curl_multi_close($mh);
