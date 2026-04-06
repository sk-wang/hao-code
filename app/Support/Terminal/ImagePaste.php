<?php

namespace App\Support\Terminal;

/**
 * Handles clipboard image detection and reading for the REPL.
 *
 * Mirrors claude-code's imagePaste.ts functionality:
 * - macOS: osascript + pbpaste for clipboard images
 * - Linux: xclip / wl-paste for clipboard images
 * - File path detection from pasted text
 * - Base64 encoding with size limits
 */
class ImagePaste
{
    /** Maximum raw image size (bytes) before resize attempt. ~5MB base64 = ~3.75MB raw */
    private const MAX_IMAGE_BYTES = 3_750_000;

    /** Supported image extensions */
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** Temp file for clipboard image extraction */
    private const CLIPBOARD_TEMP = '/tmp/haocode_clipboard_image.png';

    /**
     * Check if the clipboard currently contains an image.
     */
    public static function hasClipboardImage(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return self::macHasClipboardImage();
        }

        if (PHP_OS_FAMILY === 'Linux') {
            return self::linuxHasClipboardImage();
        }

        return false;
    }

    /**
     * Read image from clipboard and return base64 + media type.
     *
     * @return array{base64: string, media_type: string, source: string}|null
     */
    public static function getClipboardImage(): ?array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return self::macGetClipboardImage();
        }

        if (PHP_OS_FAMILY === 'Linux') {
            return self::linuxGetClipboardImage();
        }

        return null;
    }

    /**
     * Check if a string looks like an image file path.
     */
    public static function isImageFilePath(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $ext = strtolower(pathinfo($text, PATHINFO_EXTENSION));

        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Extract image file paths from pasted text (may contain multiple paths).
     *
     * @return string[]
     */
    public static function extractImagePaths(string $text): array
    {
        $paths = [];

        // Split on newlines and spaces before / (handles drag-and-drop of multiple files)
        $candidates = preg_split('/\n|\s(?=\/)/', trim($text));

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            if (self::isImageFilePath($candidate) && file_exists($candidate)) {
                $paths[] = $candidate;
            }
        }

        return $paths;
    }

    /**
     * Read an image file and return base64 + media type.
     *
     * @return array{base64: string, media_type: string, source: string}|null
     */
    public static function readImageFile(string $path): ?array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }

        $data = file_get_contents($path);
        if ($data === false || $data === '') {
            return null;
        }

        // Check size limit
        if (strlen($data) > self::MAX_IMAGE_BYTES) {
            // Try to resize with sips (macOS) or convert (ImageMagick)
            $data = self::tryResize($path, $data);
            if ($data === null || strlen($data) > self::MAX_IMAGE_BYTES) {
                return null;
            }
        }

        $mediaType = self::detectMediaType($data);
        if ($mediaType === null) {
            return null;
        }

        return [
            'base64' => base64_encode($data),
            'media_type' => $mediaType,
            'source' => basename($path),
        ];
    }

    /**
     * Detect the image media type from magic bytes.
     */
    public static function detectMediaType(string $data): ?string
    {
        if (strlen($data) < 4) {
            return null;
        }

        // PNG: 89 50 4E 47
        if (str_starts_with($data, "\x89PNG")) {
            return 'image/png';
        }

        // JPEG: FF D8 FF
        if (str_starts_with($data, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        // GIF: GIF87a or GIF89a
        if (str_starts_with($data, 'GIF8')) {
            return 'image/gif';
        }

        // WebP: RIFF....WEBP
        if (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        // BMP: 42 4D
        if (str_starts_with($data, 'BM')) {
            return 'image/png'; // We'll convert BMP to PNG
        }

        return null;
    }

    /**
     * Build an Anthropic API image content block.
     *
     * @return array{type: string, source: array{type: string, media_type: string, data: string}}
     */
    public static function buildImageBlock(string $base64, string $mediaType): array
    {
        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $base64,
            ],
        ];
    }

    // ─── macOS ──────────────────────────────────────────────────────────

    private static function macHasClipboardImage(): bool
    {
        // Check if clipboard has PNG data via osascript
        $result = shell_exec("osascript -e 'try' -e 'the clipboard as «class PNGf»' -e 'return \"yes\"' -e 'on error' -e 'return \"no\"' -e 'end try' 2>/dev/null");

        return trim($result ?? '') === 'yes';
    }

    private static function macGetClipboardImage(): ?array
    {
        $tempFile = self::CLIPBOARD_TEMP;

        // Export clipboard PNG data to file via osascript
        $script = <<<APPLESCRIPT
        try
            set imageData to the clipboard as «class PNGf»
            set filePath to POSIX file "{$tempFile}"
            set fileRef to open for access filePath with write permission
            set eof fileRef to 0
            write imageData to fileRef
            close access fileRef
            return "ok"
        on error
            return "error"
        end try
        APPLESCRIPT;

        $result = shell_exec("osascript -e " . escapeshellarg($script) . " 2>/dev/null");

        if (trim($result ?? '') !== 'ok' || !file_exists($tempFile)) {
            return null;
        }

        $data = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($data === false || $data === '') {
            return null;
        }

        if (strlen($data) > self::MAX_IMAGE_BYTES) {
            $data = self::tryResizeData($data);
            if ($data === null) {
                return null;
            }
        }

        $mediaType = self::detectMediaType($data);

        return [
            'base64' => base64_encode($data),
            'media_type' => $mediaType ?? 'image/png',
            'source' => 'clipboard',
        ];
    }

    // ─── Linux ──────────────────────────────────────────────────────────

    private static function linuxHasClipboardImage(): bool
    {
        // Try xclip first
        if (self::commandExists('xclip')) {
            $targets = shell_exec('xclip -selection clipboard -t TARGETS -o 2>/dev/null');

            return str_contains($targets ?? '', 'image/png');
        }

        // Try wl-paste (Wayland)
        if (self::commandExists('wl-paste')) {
            $types = shell_exec('wl-paste --list-types 2>/dev/null');

            return str_contains($types ?? '', 'image/png');
        }

        return false;
    }

    private static function linuxGetClipboardImage(): ?array
    {
        $tempFile = self::CLIPBOARD_TEMP;

        if (self::commandExists('xclip')) {
            shell_exec("xclip -selection clipboard -t image/png -o > " . escapeshellarg($tempFile) . " 2>/dev/null");
        } elseif (self::commandExists('wl-paste')) {
            shell_exec("wl-paste --type image/png > " . escapeshellarg($tempFile) . " 2>/dev/null");
        } else {
            return null;
        }

        if (!file_exists($tempFile) || filesize($tempFile) === 0) {
            @unlink($tempFile);
            return null;
        }

        $data = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($data === false || $data === '') {
            return null;
        }

        if (strlen($data) > self::MAX_IMAGE_BYTES) {
            $data = self::tryResizeData($data);
            if ($data === null) {
                return null;
            }
        }

        return [
            'base64' => base64_encode($data),
            'media_type' => 'image/png',
            'source' => 'clipboard',
        ];
    }

    // ─── Resize helpers ─────────────────────────────────────────────────

    /**
     * Try to resize an image file to fit within the size limit.
     */
    private static function tryResize(string $path, string $originalData): ?string
    {
        $tempOut = sys_get_temp_dir() . '/haocode_resize_' . getmypid() . '.png';

        // macOS: use sips
        if (PHP_OS_FAMILY === 'Darwin') {
            shell_exec("sips -Z 1568 " . escapeshellarg($path) . " --out " . escapeshellarg($tempOut) . " 2>/dev/null");
            if (file_exists($tempOut)) {
                $data = file_get_contents($tempOut);
                @unlink($tempOut);

                return $data !== false ? $data : null;
            }
        }

        // Linux: try ImageMagick convert
        if (self::commandExists('convert')) {
            shell_exec("convert " . escapeshellarg($path) . " -resize '1568x1568>' " . escapeshellarg($tempOut) . " 2>/dev/null");
            if (file_exists($tempOut)) {
                $data = file_get_contents($tempOut);
                @unlink($tempOut);

                return $data !== false ? $data : null;
            }
        }

        return null;
    }

    /**
     * Try to resize raw image data (in-memory).
     */
    private static function tryResizeData(string $data): ?string
    {
        // Write to temp, resize, read back
        $tempIn = sys_get_temp_dir() . '/haocode_resize_in_' . getmypid() . '.png';
        file_put_contents($tempIn, $data);
        $result = self::tryResize($tempIn, $data);
        @unlink($tempIn);

        return $result;
    }

    private static function commandExists(string $command): bool
    {
        return trim(shell_exec("which " . escapeshellarg($command) . " 2>/dev/null") ?? '') !== '';
    }
}
