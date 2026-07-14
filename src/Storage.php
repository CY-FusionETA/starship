<?php
declare(strict_types=1);

namespace App;

/** Stores and streams DO images from the protected storage/ dir. */
final class Storage
{
    /** Save raw image bytes; returns the relative path stored in the DB. */
    public static function saveImage(string $bytes, string $ext = 'jpg'): string
    {
        $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'jpg';
        $rel = 'do/' . date('Y') . '/' . date('m');
        $dir = STORAGE_ROOT . '/' . $rel;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create storage directory: $rel");
        }
        $name = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
        $abs  = $dir . '/' . $name;
        if (file_put_contents($abs, $bytes) === false || !is_file($abs)) {
            throw new \RuntimeException("Failed to write upload to storage ($rel) — check directory permissions.");
        }
        return $rel . '/' . $name;
    }

    public static function absPath(string $relPath): string
    {
        // Prevent traversal.
        $rel = str_replace(['..', "\0"], '', $relPath);
        return STORAGE_ROOT . '/' . ltrim($rel, '/');
    }

    /** Stream an image to an authenticated user. */
    public static function stream(string $relPath): void
    {
        Auth::require();
        $abs = self::absPath($relPath);
        if (!is_file($abs)) { http_response_code(404); exit('Not found'); }
        $mime = match (strtolower(pathinfo($abs, PATHINFO_EXTENSION))) {
            'png'  => 'image/png',
            'pdf'  => 'application/pdf',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=300');
        readfile($abs);
        exit;
    }
}
