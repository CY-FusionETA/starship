<?php
/**
 * Wazzup24 inbound webhook — WhatsApp DO/invoice hotline.
 * Wazzup POSTs here when a message arrives. We OCR any attached DO/invoice photo,
 * save it as a Delivery Order, and WhatsApp the extracted details back.
 *
 * URL (register this in Wazzup, superadmin Settings shows it with the token):
 *   https://starship.fusioneta.com.my/api/wazzup_webhook.php?token=<wazzup.webhook_token>
 *
 * Auth: the ?token query param must equal wazzup.webhook_token. No session/CSRF
 * (Wazzup is a server-to-server caller).
 */
define('GLOBE_APP', 1);
require __DIR__ . '/../src/bootstrap.php';

use App\Service\Wazzup\WazzupIntake;

header('Content-Type: application/json');

// Always 200 fast on Wazzup's connectivity test so the webhook can be saved.
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (is_array($body) && !empty($body['test'])) { echo json_encode(['ok' => true, 'test' => true]); exit; }

// Token guard.
$token = $_GET['token'] ?? '';
if (!is_string($token) || !hash_equals(WazzupIntake::webhookToken(), $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_array($body)) {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

// Acknowledge immediately, then process (best-effort). Flush so Wazzup isn't
// kept waiting on Gemini OCR + the reply round-trip.
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

try {
    WazzupIntake::handle($body);
} catch (\Throwable $e) {
    error_log('Wazzup webhook error: ' . $e->getMessage());
}
