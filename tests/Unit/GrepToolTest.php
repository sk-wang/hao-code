<?php

namespace Tests\Unit;

use App\Tools\Grep\GrepTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class GrepToolTest extends TestCase
{
    private GrepTool $tool;
    private ToolUseContext $context;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tool = new GrepTool;
        $this->tmpDir = sys_get_temp_dir() . '/grep_test_' . getmypid();
        mkdir($this->tmpDir, 0755, true);
        $this->context = new ToolUseContext(
            workingDirectory: $this->tmpDir,
            sessionId: 'test-session',
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpDir}/*") ?: []);
        @rmdir($this->tmpDir);
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    // ─── Force PHP fallback (bypass ripgrep) ─────────────────────────────

    /**
     * Call grepWithPhp directly to test it independently of rg availability.
     */
    private function grepPhp(
        string $pattern,
        string $path = null,
        string $outputMode = 'content',
        ?string $glob = null,
        bool $caseInsensitive = false,
        int $afterLines = 0,
        int $beforeLines = 0,
        int $headLimit = 250,
    ): \App\Tools\ToolResult {
        $ref = new \ReflectionClass(GrepTool::class);
        $method = $ref->getMethod('grepWithPhp');
        $method->setAccessible(true);

        return $method->invoke(
            $this->tool,
            $pattern,
            $path ?? $this->tmpDir,
            $outputMode,
            $glob,
            $caseInsensitive,
            $afterLines,
            $beforeLines,
            $headLimit,
        );
    }

    // ─── Basic matching ───────────────────────────────────────────────────

    public function test_it_finds_matching_lines_in_content_mode(): void
    {
        $this->writeFile('a.txt', "hello world\nfoo bar\nhello again\n");

        $result = $this->grepPhp('hello');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('hello world', $result->output);
        $this->assertStringNotContainsString('foo bar', $result->output);
    }

    public function test_it_returns_no_matches_message_when_nothing_found(): void
    {
        $this->writeFile('b.txt', "nothing here\n");

        $result = $this->grepPhp('zzzmatchnothing');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('No matches', $result->output);
    }

    public function test_it_returns_error_for_invalid_regex(): void
    {
        $result = $this->grepPhp('(unclosed[');

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Invalid regex', $result->output);
    }

    // ─── files_with_matches mode ──────────────────────────────────────────

    public function test_files_with_matches_mode_returns_only_file_paths(): void
    {
        $this->writeFile('match.txt', "needle\n");
        $this->writeFile('nomatch.txt', "haystack\n");

        $result = $this->grepPhp('needle', outputMode: 'files_with_matches');

        $this->assertStringContainsString('match.txt', $result->output);
        $this->assertStringNotContainsString('nomatch.txt', $result->output);
    }

    // ─── count mode ───────────────────────────────────────────────────────

    public function test_count_mode_returns_match_counts(): void
    {
        $this->writeFile('counted.txt', "foo\nfoo\nbar\nfoo\n");

        $result = $this->grepPhp('foo', outputMode: 'count');

        $this->assertStringContainsString('counted.txt:3', $result->output);
    }

    // ─── case insensitive ─────────────────────────────────────────────────

    public function test_case_insensitive_matches_regardless_of_case(): void
    {
        $this->writeFile('case.txt', "HELLO\nWorld\nhello\n");

        $result = $this->grepPhp('hello', caseInsensitive: true);

        $this->assertStringContainsString('HELLO', $result->output);
        $this->assertStringContainsString('hello', $result->output);
    }

    // ─── glob filtering ───────────────────────────────────────────────────

    public function test_glob_filter_excludes_non_matching_extensions(): void
    {
        $this->writeFile('code.php', "needle\n");
        $this->writeFile('doc.md', "needle\n");

        $result = $this->grepPhp('needle', glob: '*.php', outputMode: 'files_with_matches');

        $this->assertStringContainsString('code.php', $result->output);
        $this->assertStringNotContainsString('doc.md', $result->output);
    }

    public function test_glob_filter_works_with_subdirectory_path_pattern(): void
    {
        // Create a nested structure: src/code.php and docs/code.php
        // A glob of 'src/*.php' should match only the one in src/
        $srcDir = $this->tmpDir . '/src';
        $docsDir = $this->tmpDir . '/docs';
        mkdir($srcDir, 0755, true);
        mkdir($docsDir, 0755, true);
        file_put_contents($srcDir . '/code.php', "needle\n");
        file_put_contents($docsDir . '/code.php', "needle\n");

        $result = $this->grepPhp('needle', glob: 'src/*.php', outputMode: 'files_with_matches');

        $this->assertStringContainsString('src/code.php', $result->output);
        $this->assertStringNotContainsString('docs/code.php', $result->output,
            'docs/code.php should be excluded by glob src/*.php');

        // Cleanup
        unlink($srcDir . '/code.php');
        unlink($docsDir . '/code.php');
        rmdir($srcDir);
        rmdir($docsDir);
    }

    // ─── head_limit ───────────────────────────────────────────────────────

    public function test_head_limit_caps_number_of_matches(): void
    {
        $lines = implode("\n", array_fill(0, 20, 'match'));
        $this->writeFile('many.txt', $lines . "\n");

        $result = $this->grepPhp('match', headLimit: 3);

        $matchCount = substr_count($result->output, 'match');
        $this->assertLessThanOrEqual(3, $matchCount);
    }

    // ─── single file search ───────────────────────────────────────────────

    public function test_it_searches_a_single_file_directly(): void
    {
        $file = $this->writeFile('single.txt', "apple\nbanana\napricot\n");

        $result = $this->grepPhp('apple', path: $file);

        $this->assertStringContainsString('apple', $result->output);
        $this->assertStringNotContainsString('banana', $result->output);
    }

    // ─── context lines (the bug that was fixed) ───────────────────────────

    public function test_after_context_lines_are_included(): void
    {
        $this->writeFile('ctx.txt', "before\nmatch_line\nafter1\nafter2\nunrelated\n");

        $result = $this->grepPhp('match_line', afterLines: 2);

        $this->assertStringContainsString('match_line', $result->output);
        $this->assertStringContainsString('after1', $result->output);
        $this->assertStringContainsString('after2', $result->output);
        $this->assertStringNotContainsString('unrelated', $result->output);
    }

    public function test_before_context_lines_are_included(): void
    {
        $this->writeFile('bctx.txt', "unrelated\nbefore2\nbefore1\nmatch_line\nafter\n");

        $result = $this->grepPhp('match_line', beforeLines: 2);

        $this->assertStringContainsString('match_line', $result->output);
        $this->assertStringContainsString('before2', $result->output);
        $this->assertStringContainsString('before1', $result->output);
        $this->assertStringNotContainsString('unrelated', $result->output);
    }

    public function test_context_lines_do_not_duplicate_when_matches_are_adjacent(): void
    {
        $this->writeFile('adj.txt', "match1\nmatch2\nother\n");

        $result = $this->grepPhp('match', afterLines: 1);

        // match1 and match2 are adjacent; match1's after-context is match2,
        // which is itself a match — it should appear exactly once
        $count = substr_count($result->output, 'match2');
        $this->assertSame(1, $count);
    }

    public function test_no_context_lines_without_flags(): void
    {
        $this->writeFile('noctx.txt', "before\nmatch_here\nafter\n");

        $result = $this->grepPhp('match_here');

        $this->assertStringContainsString('match_here', $result->output);
        $this->assertStringNotContainsString('before', $result->output);
        $this->assertStringNotContainsString('after', $result->output);
    }

    // ─── patterns containing forward slash ───────────────────────────────

    public function test_pattern_with_forward_slash_is_not_rejected_as_invalid_regex(): void
    {
        // Patterns like `app/Services` or `foo/bar` contain `/` which is the PHP
        // regex delimiter. Before the fix this would cause grepWithPhp() to report
        // an invalid regex error instead of searching for the literal slash.
        $this->writeFile('paths.txt', "app/Services/Foo.php\napp/Models/Bar.php\n");

        $result = $this->grepPhp('app/Services');

        $this->assertFalse($result->isError, 'Pattern with / should not be treated as invalid regex');
        $this->assertStringContainsString('app/Services/Foo.php', $result->output);
    }

    public function test_pattern_with_forward_slash_matches_correctly(): void
    {
        $this->writeFile('urls.txt', "https://example.com/api/v1\nhttps://example.com/home\n");

        $result = $this->grepPhp('api/v1');

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('api/v1', $result->output);
        $this->assertStringNotContainsString('home', $result->output);
    }

    // ─── isReadOnly ───────────────────────────────────────────────────────

    public function test_grep_tool_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }
}
