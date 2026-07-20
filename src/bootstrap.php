<?php
/**
 * Bootstrap — every entry point (index.php, api/*, cron/*) includes this first.
 * It loads config, registers the autoloader, sets the timezone, and (for web
 * requests) starts the session.
 */
declare(strict_types=1);

if (!defined('GLOBE_APP')) define('GLOBE_APP', 1);

define('APP_ROOT', dirname(__DIR__));           // .../globe-starship
define('SRC_ROOT', APP_ROOT . '/src');
define('STORAGE_ROOT', APP_ROOT . '/storage');
define('VIEW_ROOT', APP_ROOT . '/views');

// --- config ---------------------------------------------------------
$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration missing. Copy config/config.sample.php to config/config.php.');
}
$GLOBALS['config'] = require $configFile;

date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'Asia/Kuala_Lumpur');

// --- autoloader (App\ -> src/) --------------------------------------
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = SRC_ROOT . '/' . $rel . '.php';
    if (is_file($file)) require $file;
});

// --- error handling -------------------------------------------------
error_reporting(E_ALL);
$isProd = (($GLOBALS['config']['app']['env'] ?? 'production') === 'production');
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_ROOT . '/logs/php-error.log');

/**
 * An uncaught exception must never reach the browser as a blank 500 — that is
 * what a failed save looked like before: the work was lost with nothing on
 * screen to say why. Log the full trace, then render the normal error view so
 * the user still has the nav and can get back to what they were doing.
 */
if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $ex) use ($isProd): void {
        error_log('Uncaught ' . get_class($ex) . ': ' . $ex->getMessage()
            . ' in ' . $ex->getFile() . ':' . $ex->getLine() . "\n" . $ex->getTraceAsString());

        while (ob_get_level() > 0) ob_end_clean();   // drop a half-rendered page
        if (!headers_sent()) http_response_code(500);

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        if (str_ends_with($path, '.json') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Something went wrong on our end.']);
            return;
        }

        // Off production the real message is far more useful than a polite one.
        $msg = $isProd
            ? 'Something went wrong on our end. Nothing was saved — please try again, and tell Simon if it keeps happening.'
            : get_class($ex) . ': ' . $ex->getMessage();
        try {
            App\Response::view('error', ['message' => $msg], 'Error');
        } catch (Throwable $_) {
            echo '<h1>Something went wrong</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    });
}

/** Global config accessor. */
function cfg(string $path, $default = null) {
    $parts = explode('.', $path);
    $node = $GLOBALS['config'];
    foreach ($parts as $p) {
        if (!is_array($node) || !array_key_exists($p, $node)) return $default;
        $node = $node[$p];
    }
    return $node;
}

/** HTML-escape helper. */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
