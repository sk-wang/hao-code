<?php

namespace Tests\Unit;

use App\Support\Terminal\ImagePaste;
use PHPUnit\Framework\TestCase;

class ImagePasteTest extends TestCase
{
    // ─── isImageFilePath ────────────────────────────────────────────────

    public function test_recognizes_png(): void
    {
        $this->assertTrue(ImagePaste::isImageFilePath('/tmp/screenshot.png'));
    }

    public function test_recognizes_jpg(): void
    {
        $this->assertTrue(ImagePaste::isImageFilePath('/tmp/photo.jpg'));
    }

    public function test_recognizes_jpeg(): void
    {
        $this->assertTrue(ImagePaste::isImageFilePath('/tmp/photo.jpeg'));
    }

    public function test_recognizes_gif(): void
    {
        $this->assertTrue(ImagePaste::isImageFilePath('/tmp/animation.gif'));
    }

    public function test_recognizes_webp(): void
    {
        $this->assertTrue(ImagePaste::isImageFilePath('/tmp/image.webp'));
    }

    public function test_rejects_non_image_extension(): void
    {
        $this->assertFalse(ImagePaste::isImageFilePath('/tmp/file.txt'));
        $this->assertFalse(ImagePaste::isImageFilePath('/tmp/code.php'));
        $this->assertFalse(ImagePaste::isImageFilePath('/tmp/data.json'));
    }

    public function test_rejects_empty_string(): void
    {
        $this->assertFalse(ImagePaste::isImageFilePath(''));
        $this->assertFalse(ImagePaste::isImageFilePath('   '));
    }

    // ─── extractImagePaths ──────────────────────────────────────────────

    public function test_extracts_single_image_path(): void
    {
        $tmpFile = $this->createTempImage('test.png');
        $paths = ImagePaste::extractImagePaths($tmpFile);

        $this->assertCount(1, $paths);
        $this->assertSame($tmpFile, $paths[0]);

        @unlink($tmpFile);
    }

    public function test_extracts_multiple_paths_from_newlines(): void
    {
        $tmp1 = $this->createTempImage('img1.png');
        $tmp2 = $this->createTempImage('img2.jpg');

        $paths = ImagePaste::extractImagePaths("{$tmp1}\n{$tmp2}");
        $this->assertCount(2, $paths);

        @unlink($tmp1);
        @unlink($tmp2);
    }

    public function test_ignores_non_existing_paths(): void
    {
        $paths = ImagePaste::extractImagePaths('/nonexistent/file.png');
        $this->assertEmpty($paths);
    }

    public function test_ignores_non_image_files(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test') . '.txt';
        file_put_contents($tmp, 'hello');

        $paths = ImagePaste::extractImagePaths($tmp);
        $this->assertEmpty($paths);

        @unlink($tmp);
    }

    // ─── detectMediaType ────────────────────────────────────────────────

    public function test_detects_png_from_magic_bytes(): void
    {
        $this->assertSame('image/png', ImagePaste::detectMediaType("\x89PNG\r\n\x1a\n"));
    }

    public function test_detects_jpeg_from_magic_bytes(): void
    {
        $this->assertSame('image/jpeg', ImagePaste::detectMediaType("\xFF\xD8\xFF\xE0"));
    }

    public function test_detects_gif_from_magic_bytes(): void
    {
        $this->assertSame('image/gif', ImagePaste::detectMediaType("GIF89a"));
    }

    public function test_detects_webp_from_magic_bytes(): void
    {
        $this->assertSame('image/webp', ImagePaste::detectMediaType("RIFF\x00\x00\x00\x00WEBP"));
    }

    public function test_returns_null_for_unknown_format(): void
    {
        $this->assertNull(ImagePaste::detectMediaType("random data here"));
    }

    public function test_returns_null_for_short_data(): void
    {
        $this->assertNull(ImagePaste::detectMediaType("ab"));
    }

    // ─── buildImageBlock ────────────────────────────────────────────────

    public function test_builds_correct_api_format(): void
    {
        $block = ImagePaste::buildImageBlock('abc123==', 'image/png');

        $this->assertSame('image', $block['type']);
        $this->assertSame('base64', $block['source']['type']);
        $this->assertSame('image/png', $block['source']['media_type']);
        $this->assertSame('abc123==', $block['source']['data']);
    }

    // ─── readImageFile ──────────────────────────────────────────────────

    public function test_reads_small_png_file(): void
    {
        $tmp = $this->createTempImage('read_test.png');
        $result = ImagePaste::readImageFile($tmp);

        $this->assertNotNull($result);
        $this->assertSame('image/png', $result['media_type']);
        $this->assertNotEmpty($result['base64']);
        $this->assertSame('read_test.png', $result['source']);

        @unlink($tmp);
    }

    public function test_returns_null_for_nonexistent_file(): void
    {
        $this->assertNull(ImagePaste::readImageFile('/nonexistent/file.png'));
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Create a minimal valid PNG file for testing.
     */
    private function createTempImage(string $name): string
    {
        $path = sys_get_temp_dir() . '/' . $name;

        // Minimal 1x1 PNG (67 bytes)
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );

        file_put_contents($path, $png);

        return $path;
    }
}
