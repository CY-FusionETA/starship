<?php
/**
 * Applies db/schema.sql. Idempotent (CREATE TABLE IF NOT EXISTS).
 * CLI:  php db/migrate.php
 * Web:  /db/migrate.php?token=<app_key>   (token must match config app_key)
 * Delete or re-protect after first run in production.
 */
define('GLOBE_APP', 1);
require __DIR__ . '/../src/bootstrap.php';

use App\Db;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if (!hash_equals(cfg('app.app_key', ''), $token)) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: text/plain');
}

$pdo = Db::conn();

$runFile = function (string $path) use ($pdo): array {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($path));
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $ok = 0; $fail = 0;
    // Errors that mean "already applied" — safe to ignore for idempotent re-runs.
    $benign = ['1050', '1060', '1061', '1091', '42S21'];
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        try { $pdo->exec($stmt); $ok++; }
        catch (\Throwable $ex) {
            $code = (string)($ex->getCode());
            $msg  = $ex->getMessage();
            $isBenign = in_array($code, $benign, true)
                || stripos($msg, 'Duplicate column') !== false
                || stripos($msg, 'already exists') !== false;
            if ($isBenign) { $ok++; continue; }
            $fail++;
            echo "ERROR: " . $msg . "\n";
            echo "  in: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 90) . "...\n";
        }
    }
    return [$ok, $fail];
};

[$ok, $fail] = $runFile(__DIR__ . '/schema.sql');
echo "Base schema — OK: {$ok}, failed: {$fail}\n";

foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $mig) {
    [$mo, $mf] = $runFile($mig);
    echo "Migration " . basename($mig) . " — OK: {$mo}, failed: {$mf}\n";
}
echo "Migration complete.\n";
