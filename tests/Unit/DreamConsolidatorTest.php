<?php

namespace Tests\Unit;

use App\Services\Memory\ConsolidationLock;
use App\Services\Memory\DreamConsolidator;
use App\Services\Memory\SessionMemory;
use PHPUnit\Framework\TestCase;

class DreamConsolidatorTest extends TestCase
{
    private string $tmpDir;

    private string $originalHome = '';

    private DreamConsolidator $consolidator;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/dream_consolidator_test_'.getmypid();
        mkdir($this->tmpDir.'/.haocode', 0755, true);

        $this->originalHome = $_SERVER['HOME'] ?? '';
        $_SERVER['HOME'] = $this->tmpDir;

        $memory = new SessionMemory;
        $lock = new ConsolidationLock;
        $this->consolidator = new DreamConsolidator($memory, $lock, $this->tmpDir.'/sessions');
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            $_SERVER['HOME'] = $this->originalHome;
        } else {
            unset($_SERVER['HOME']);
        }

        $files = [
            $this->tmpDir.'/.haocode/.consolidate-lock',
            $this->tmpDir.'/.haocode/memory.json',
        ];
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir.'/.haocode')) {
            rmdir($this->tmpDir.'/.haocode');
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function test_build_consolidation_prompt_is_non_empty_string(): void
    {
        $prompt = $this->consolidator->buildConsolidationPrompt('/mem', '/transcripts');
        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public function test_build_consolidation_prompt_contains_phases(): void
    {
        $prompt = $this->consolidator->buildConsolidationPrompt('/mem', '/transcripts');

        $this->assertStringContainsString('Phase 1', $prompt);
        $this->assertStringContainsString('Phase 2', $prompt);
        $this->assertStringContainsString('Phase 3', $prompt);
        $this->assertStringContainsString('Phase 4', $prompt);
    }

    public function test_build_consolidation_prompt_contains_paths(): void
    {
        $prompt = $this->consolidator->buildConsolidationPrompt('/my/mem/dir', '/my/transcripts');

        $this->assertStringContainsString('/my/mem/dir', $prompt);
        $this->assertStringContainsString('/my/mem/dir/memory.json', $prompt);
        $this->assertStringContainsString('/my/transcripts', $prompt);
    }

    public function test_build_consolidation_prompt_limits_memory_access_to_memory_file(): void
    {
        $prompt = $this->consolidator->buildConsolidationPrompt('/mem', '/transcripts');

        $this->assertStringContainsString('Persistent memory file: `/mem/memory.json`', $prompt);
        $this->assertStringContainsString('Do not list the parent directory', $prompt);
        $this->assertStringContainsString('settings.json', $prompt);
        $this->assertStringNotContainsString('Memory root:', $prompt);
        $this->assertStringNotContainsString('List what already exists in the memory directory', $prompt);
    }

    public function test_build_consolidation_prompt_mentions_memory_operations(): void
    {
        $prompt = $this->consolidator->buildConsolidationPrompt('/mem', '/tr');

        $this->assertStringContainsString('memory', strtolower($prompt));
        $this->assertStringContainsString('consolidat', strtolower($prompt));
    }

    public function test_get_memory_root_returns_haocode_path(): void
    {
        $root = $this->consolidator->getMemoryRoot();
        $this->assertStringContainsString('.haocode', $root);
    }

    public function test_get_transcript_dir_returns_string(): void
    {
        $dir = $this->consolidator->getTranscriptDir();
        $this->assertIsString($dir);
        $this->assertNotEmpty($dir);
    }

    public function test_get_memory_stats_returns_valid_structure(): void
    {
        $stats = $this->consolidator->getMemoryStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('count', $stats);
        $this->assertArrayHasKey('total_chars', $stats);
        $this->assertArrayHasKey('last_consolidated', $stats);
        $this->assertIsInt($stats['count']);
        $this->assertIsInt($stats['total_chars']);
        $this->assertIsInt($stats['last_consolidated']);
    }

    public function test_record_consolidation_does_not_throw(): void
    {
        // Simply verify it doesn't throw an exception
        $this->consolidator->recordConsolidation();
        $this->assertTrue(true);
    }

    public function test_memory_stats_reflects_stored_memories(): void
    {
        // Use SessionMemory directly to set some memories
        $_SERVER['HOME'] = $this->tmpDir;
        $memory = new SessionMemory;
        $memory->set('key1', 'value1');
        $memory->set('key2', 'a longer value here');

        $lock = new ConsolidationLock;
        $consolidator = new DreamConsolidator($memory, $lock);

        $stats = $consolidator->getMemoryStats();

        $this->assertSame(2, $stats['count']);
        $this->assertGreaterThan(0, $stats['total_chars']);
    }

    public function test_consolidation_prompt_guides_through_full_workflow(): void
    {
        $prompt = $this->consolidator->buildConsolidationPrompt('/mem', '/tr');

        // Verify the prompt covers all key steps
        $this->assertStringContainsString('Orient', $prompt);
        $this->assertStringContainsString('Gather', $prompt);
        $this->assertStringContainsString('Consolidate', $prompt);
        $this->assertStringContainsString('Prune', $prompt);
    }

    public function test_consolidation_prompt_suggests_grep_narrowly(): void
    {
        $prompt = $this->consolidator->buildConsolidationPrompt('/mem', '/tr');
        $this->assertStringContainsString('grep', strtolower($prompt));
    }
}
