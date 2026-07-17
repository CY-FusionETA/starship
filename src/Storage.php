<?php
declare(strict_types=1);

namespace App;

/** Stores and streams uploads (DO images, MR quotations) from the protected storage/ dir. */
final class Storage
{
    /** Save raw DO image bytes; returns the relative path stored in the DB. */
    public static function saveImage(string $bytes, string $ext = 'jpg'): string
    {
        return self::saveFile($bytes, $ext, 'do');
    }

    /** Save raw bytes under storage/<prefix>/<year>/<month>/; returns the relative path. */
    public static function saveFile(string $bytes, string $ext = 'jpg', string $prefix = 'do'): string
    {
        $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'jpg';
        $prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'do';
        $rel = $prefix . '/' . date('Y') . '/' . date('m');
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

    /** Delete a stored file; missing files are not an error. */
    public static function delete(string $relPath): void
    {
        $abs = self::absPath($relPath);
        if (is_file($abs)) @unlink($abs);
    }

    /**
     * Stream a stored file to an authenticated user.
     * $asName, when given, is the filename the browser shows on save/print.
     */
    public static function stream(string $relPath, ?string $asName = null): void
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
        if ($asName !== null && $asName !== '') {
            // Strip anything that could break out of the header or the filename.
            $safe = str_replace(['"', "\r", "\n", '\\', '/'], '', $asName);
            header('Content-Disposition: inline; filename="' . $safe . '"');
        }
        readfile($abs);
        exit;
    }
}
