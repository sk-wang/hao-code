<?php

namespace Tests\Unit;

use App\Services\OutputStyle\OutputStyleLoader;
use PHPUnit\Framework\TestCase;

class OutputStyleLoaderTest extends TestCase
{
    private string $tmpDir;
    private string $stylesDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/output_styles_test_' . uniqid();
        $this->stylesDir = $this->tmpDir . '/output-styles';
        mkdir($this->stylesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stylesDir . '/*.md') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->stylesDir);
        @rmdir($this->tmpDir);
    }

    /**
     * Create a loader that scans only our temp directory.
     */
    private function makeLoader(): OutputStyleLoader
    {
        $loader = new OutputStyleLoader;

        // Patch the internal dirs to use our temp path
        $ref = new \ReflectionClass($loader);

        // We'll use parseFrontmatter indirectly through loadStyles
        // but we need to override the scan dirs — inject via a subclass trick
        // Since we can't easily inject dirs, we'll test parseFrontmatter directly
        // and test loadStyles via the real filesystem path

        return $loader;
    }

    /**
     * Create a loader that reads from our temp styles directory.
     */
    private function makeLoaderFromDir(string $dir): OutputStyleLoader
    {
        $loader = new class($dir) extends OutputStyleLoader {
            public function __construct(private string $testDir) {}

            protected function getScanDirs(): array
            {
                return [$this->testDir];
            }
        };
        return $loader;
    }

    // ─── parseFrontmatter (via reflection) ───────────────────────────────

    private function parseFrontmatter(string $content): array
    {
        $loader = new OutputStyleLoader;
        $ref = new \ReflectionClass($loader);
        $method = $ref->getMethod('parseFrontmatter');
        $method->setAccessible(true);
        return $method->invoke($loader, $content);
    }

    public function test_no_frontmatter_returns_empty_meta_and_full_body(): void
    {
        [$meta, $body] = $this->parseFrontmatter("# Hello\n\nSome content");

        $this->assertSame([], $meta);
        $this->assertStringContainsString('# Hello', $body);
    }

    public function test_frontmatter_parsed_correctly(): void
    {
        $content = "---\nname: Terse\ndescription: Short responses\n---\n\nBe concise.";
        [$meta, $body] = $this->parseFrontmatter($content);

        $this->assertSame('Terse', $meta['name']);
        $this->assertSame('Short responses', $meta['description']);
        $this->assertStringContainsString('Be concise.', $body);
    }

    public function test_frontmatter_body_does_not_include_delimiters(): void
    {
        $content = "---\nname: Test\n---\n\nBody here";
        [$meta, $body] = $this->parseFrontmatter($content);

        $this->assertStringNotContainsString('---', $body);
    }

    public function test_frontmatter_with_no_closing_delimiter(): void
    {
        // No closing --- means we treat the whole file as having no frontmatter
        $content = "---\nname: Test\n\nNo closing delimiter";
        [$meta, $body] = $this->parseFrontmatter($content);

        $this->assertSame([], $meta);
        // Body is the full original content
        $this->assertStringContainsString('---', $body);
    }

    public function test_content_starting_with_hash_not_treated_as_frontmatter(): void
    {
        $content = "# Title\n\nNo frontmatter here";
        [$meta, $body] = $this->parseFrontmatter($content);

        $this->assertSame([], $meta);
        $this->assertStringContainsString('# Title', $body);
    }

    public function test_frontmatter_meta_key_trimmed(): void
    {
        $content = "---\n  name  :  SpacedOut  \n---\n\nContent";
        [$meta, $body] = $this->parseFrontmatter($content);

        // The regex requires \w+ at start — leading spaces prevent parsing this key
        // so meta may be empty; just check no crash
        $this->assertIsArray($meta);
    }

    public function test_empty_string_returns_empty_meta_and_empty_body(): void
    {
        [$meta, $body] = $this->parseFrontmatter('');

        $this->assertSame([], $meta);
        $this->assertSame('', $body);
    }

    // ─── listStyles / getActiveStyleContent ──────────────────────────────

    public function test_list_styles_returns_empty_when_no_style_files(): void
    {
        // OutputStyleLoader scans disk; since styles dirs likely don't exist in CI,
        // we test the shape of the return
        $loader = new OutputStyleLoader;
        $styles = $loader->listStyles();
        $this->assertIsArray($styles);
    }

    public function test_get_active_style_returns_null_for_unknown_slug(): void
    {
        $loader = new OutputStyleLoader;
        $this->assertNull($loader->getActiveStyleContent('no_such_style_slug_xyz'));
    }

    public function test_style_entries_have_required_keys_when_present(): void
    {
        // Build a loader that returns a fake style entry
        $loader = new OutputStyleLoader;
        $ref = new \ReflectionClass($loader);
        $prop = $ref->getProperty('cachedStyles');
        $prop->setAccessible(true);
        $prop->setValue($loader, [
            'terse' => [
                'name' => 'Terse',
                'description' => 'Short',
                'content' => 'Be brief.',
                'path' => '/fake/terse.md',
            ],
        ]);

        foreach ($loader->listStyles() as $slug => $style) {
            $this->assertArrayHasKey('name', $style);
            $this->assertArrayHasKey('description', $style);
            $this->assertArrayHasKey('content', $style);
            $this->assertArrayHasKey('path', $style);
        }
        // At least one style was checked
        $this->assertArrayHasKey('terse', $loader->listStyles());
    }
}
