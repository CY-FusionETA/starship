<?php
/**
 * Scheduled Xero → Starship pull. Mirrors Xero Contacts → suppliers, the
 * "Project" tracking category → projects, and Items → catalogue. All upserts
 * are idempotent, so re-running is safe. One data type failing (e.g. no Project
 * tracking category yet) never blocks the others.
 *
 * Cron (runs as www-data, every 15 min):
 *   *\/15 * * * * php /var/www/starship/cron/xero_autosync.php
 * Or, on a host without shell cron, hit it over HTTP with the app key:
 *   /cron/xero_autosync.php?token=<app_key>
 */
declare(strict_types=1);
define('GLOBE_APP', 1);
require __DIR__ . '/../src/bootstrap.php';

use App\Settings;
use App\Service\Xero\XeroOAuth;
use App\Service\Xero\XeroSync;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain');
    if (!hash_equals((string)cfg('app.app_key', ''), (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$ts  = date('Y-m-d H:i:s');
$log = function (string $line) use ($ts): void {
    $msg = "[$ts] $line\n";
    echo $msg;
    $dir = STORAGE_ROOT . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    @file_put_contents($dir . '/xero_sync.log', $msg, FILE_APPEND);
};

if (!Settings::bool('xero.enabled') || !XeroOAuth::isConnected()) {
    $log('xero-autosync skipped — Xero not connected.');
    exit(0);
}

$summary = [];
foreach (['contacts' => 'pullContacts', 'projects' => 'pullProjects', 'items' => 'pullItems'] as $label => $method) {
    try {
        $r = XeroSync::$method();
        $summary[$label] = $r;
        $log(sprintf('%-8s created %d, updated %d (of %d)', $label, $r['created'], $r['updated'], $r['total']));
    } catch (\Throwable $e) {
        $summary[$label] = ['error' => $e->getMessage()];
        $log(sprintf('%-8s SKIPPED — %s', $label, $e->getMessage()));
    }
}

// Stash the last run so the Settings page can show "last synced …".
Settings::set('xero.autosync_last', json_encode(['at' => $ts, 'summary' => $summary], JSON_UNESCAPED_UNICODE));
$log('xero-autosync done.');
