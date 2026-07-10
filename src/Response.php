<?php
declare(strict_types=1);

namespace App;

/** Response helpers: render views, JSON, redirects. */
final class Response
{
    /** Render a view within the layout. $view is a path under views/ without extension. */
    public static function view(string $view, array $data = [], string $title = ''): void
    {
        extract($data, EXTR_SKIP);
        $__view = VIEW_ROOT . '/' . $view . '.php';
        ob_start();
        require $__view;
        $content = ob_get_clean();
        require VIEW_ROOT . '/layout.php';
    }

    /** Render a view with no layout (partials, print). */
    public static function partial(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require VIEW_ROOT . '/' . $view . '.php';
    }

    public static function json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $path): void
    {
        // Absolute app path -> prefix base path.
        if ($path[0] === '/') {
            $base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
            $path = $base . $path;
        }
        header('Location: ' . $path);
        exit;
    }

    public static function notFound(string $msg = 'Not found'): void
    {
        http_response_code(404);
        self::view('error', ['message' => $msg], 'Not found');
        exit;
    }
}
