<?php

namespace Tests\Unit;

use App\Tools\Bash\CommandClassifier;
use PHPUnit\Framework\TestCase;

class CommandClassifierTest extends TestCase
{
    // ─── search classification ──────────────────────────────────────────

    public function test_grep_is_search(): void
    {
        $r = CommandClassifier::classify('grep -r "TODO" src/');
        $this->assertTrue($r['isSearch']);
    }

    public function test_rg_is_search(): void
    {
        $r = CommandClassifier::classify('rg "pattern" --type php');
        $this->assertTrue($r['isSearch']);
    }

    public function test_find_is_search(): void
    {
        $r = CommandClassifier::classify('find . -name "*.php"');
        $this->assertTrue($r['isSearch']);
    }

    // ─── read classification ────────────────────────────────────────────

    public function test_cat_is_read(): void
    {
        $r = CommandClassifier::classify('cat /etc/hosts');
        $this->assertTrue($r['isRead']);
    }

    public function test_head_is_read(): void
    {
        $r = CommandClassifier::classify('head -20 file.txt');
        $this->assertTrue($r['isRead']);
    }

    public function test_jq_is_read(): void
    {
        $r = CommandClassifier::classify('jq ".data" file.json');
        $this->assertTrue($r['isRead']);
    }

    // ─── list classification ────────────────────────────────────────────

    public function test_ls_is_list(): void
    {
        $r = CommandClassifier::classify('ls -la /tmp');
        $this->assertTrue($r['isList']);
    }

    public function test_tree_is_list(): void
    {
        $r = CommandClassifier::classify('tree src/');
        $this->assertTrue($r['isList']);
    }

    // ─── pipeline classification ────────────────────────────────────────

    public function test_pipe_to_grep_is_search(): void
    {
        $r = CommandClassifier::classify('cat file.txt | grep pattern');
        // All parts are read/search, so it should be classified
        $this->assertTrue($r['isSearch'] || $r['isRead']);
    }

    public function test_compound_search_commands(): void
    {
        $r = CommandClassifier::classify('grep -r "TODO" && grep -r "FIXME"');
        $this->assertTrue($r['isSearch']);
    }

    // ─── non-search/read commands ───────────────────────────────────────

    public function test_npm_install_is_not_search(): void
    {
        $r = CommandClassifier::classify('npm install');
        $this->assertFalse($r['isSearch']);
        $this->assertFalse($r['isRead']);
        $this->assertFalse($r['isList']);
    }

    public function test_git_commit_is_not_search(): void
    {
        $r = CommandClassifier::classify('git commit -m "fix"');
        $this->assertFalse($r['isSearch']);
    }

    public function test_mixed_commands_not_classified(): void
    {
        $r = CommandClassifier::classify('grep pattern && npm install');
        // npm install makes it non-search
        $this->assertFalse($r['isSearch']);
    }

    // ─── neutral commands ───────────────────────────────────────────────

    public function test_echo_in_pipeline_is_neutral(): void
    {
        $r = CommandClassifier::classify('ls -la && echo "---" && ls /tmp');
        $this->assertTrue($r['isList']);
    }

    // ─── silent commands ────────────────────────────────────────────────

    public function test_mkdir_is_silent(): void
    {
        $this->assertTrue(CommandClassifier::isSilent('mkdir -p /tmp/test'));
    }

    public function test_cp_is_silent(): void
    {
        $this->assertTrue(CommandClassifier::isSilent('cp a.txt b.txt'));
    }

    public function test_grep_is_not_silent(): void
    {
        $this->assertFalse(CommandClassifier::isSilent('grep pattern file'));
    }

    // ─── destructive ────────────────────────────────────────────────────

    public function test_rm_is_destructive(): void
    {
        $this->assertTrue(CommandClassifier::isDestructive('rm -rf /tmp/test'));
    }

    public function test_ls_is_not_destructive(): void
    {
        $this->assertFalse(CommandClassifier::isDestructive('ls'));
    }

    // ─── concurrency safety ─────────────────────────────────────────────

    public function test_grep_is_concurrency_safe(): void
    {
        $this->assertTrue(CommandClassifier::isConcurrencySafe('grep pattern file'));
    }

    public function test_npm_install_is_not_concurrency_safe(): void
    {
        $this->assertFalse(CommandClassifier::isConcurrencySafe('npm install'));
    }
}
