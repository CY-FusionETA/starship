<?php
/**
 * Dev-only router for the PHP built-in server:
 *     php -S localhost:8000 router.php
 * Serves real static files, routes everything else to index.php.
 * Production uses Apache (.htaccess) or Nginx (deploy/nginx.conf.example) instead.
 */
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$cand = realpath($root . $path);
if ($path !== '/' && $cand && is_file($cand) && str_starts_with($cand, $root) && !str_contains($path, 'index.php')) {
    return false; // let the built-in server serve the static file
}
require $root . '/index.php';
