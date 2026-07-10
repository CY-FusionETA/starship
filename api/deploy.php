<?php
/**
 * HTTPS self-deploy endpoint — the FTP-free update channel.
 * POST a zip as multipart field "package" with the deploy token; it extracts
 * into the app dir. Guarded by config deploy.token and a deploy.enabled flag.
 *
 * SECURITY: this can write PHP into the webroot, so the token IS the boundary.
 * Keep it strong, and set deploy.enabled=false (or delete this file) at go-live.
 *
 *   curl -F "token=<deploy_token>" -F "package=@build.zip" \
 *        https://.../web/globe-starship/api/deploy.php
 */
define('GLOBE_APP', 1);
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json');

function deploy_fail(int $code, string $msg): void { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

if (!cfg('deploy.enabled')) deploy_fail(404, 'not found');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') deploy_fail(405, 'POST only');

$token = $_POST['token'] ?? ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '');
if (!is_string($token) || !hash_equals((string)cfg('deploy.token'), $token)) deploy_fail(403, 'bad token');

if (!class_exists('ZipArchive')) deploy_fail(500, 'ZipArchive not available on server');
if (empty($_FILES['package']['tmp_name']) || !is_uploaded_file($_FILES['package']['tmp_name'])) deploy_fail(400, 'no package uploaded');

$zip = new ZipArchive();
if ($zip->open($_FILES['package']['tmp_name']) !== true) deploy_fail(400, 'cannot open zip');

$root = realpath(APP_ROOT);
$written = []; $skipped = [];
// Files we refuse to overwrite from a package (protect server secrets).
$protected = ['config/config.php'];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === false || str_ends_with($name, '/')) continue;             // dirs
    // Strip an optional leading "globe-starship/" wrapper so both zip shapes work.
    $rel = preg_replace('#^globe-starship/#', '', $name);
    if ($rel === '' ) continue;
    if (str_contains($rel, '..') || str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:#', $rel)) {
        $skipped[] = $name; continue;                                       // traversal / absolute
    }
    if (in_array($rel, $protected, true)) { $skipped[] = $rel; continue; }

    $dest = $root . '/' . $rel;
    $destReal = dirname($dest);
    if (!is_dir($destReal)) @mkdir($destReal, 0770, true);
    // Ensure the resolved directory stays inside the app root.
    $checkDir = realpath($destReal);
    if ($checkDir === false || strncmp($checkDir, $root, strlen($root)) !== 0) { $skipped[] = $rel; continue; }

    $bytes = $zip->getFromIndex($i);
    if ($bytes === false) { $skipped[] = $rel; continue; }
    file_put_contents($dest, $bytes);
    $written[] = $rel;
}
$zip->close();

echo json_encode(['ok' => true, 'written' => count($written), 'skipped' => $skipped, 'files' => $written]);
