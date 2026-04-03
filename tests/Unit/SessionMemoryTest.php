<?php

namespace Tests\Unit;

use App\Services\Memory\SessionMemory;
use PHPUnit\Framework\TestCase;

class SessionMemoryTest extends TestCase
{
    private string $tmpDir;
    private SessionMemory $memory;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/haocode_memory_test_' . getmypid();
        mkdir($this->tmpDir, 0755, true);

        // Point SessionMemory at our tmp dir by overriding HOME
        $_SERVER['HOME'] = $this->tmpDir;
        $this->memory = new SessionMemory;
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/.haocode/memory.json';
        if (file_exists($file)) {
            unlink($file);
        }
        $dir = $this->tmpDir . '/.haocode';
        if (is_dir($dir)) {
            rmdir($dir);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function test_set_and_get_a_value(): void
    {
        $this->memory->set('model', 'claude-sonnet-4-6');

        $this->assertSame('claude-sonnet-4-6', $this->memory->get('model'));
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->memory->get('nonexistent'));
    }

    public function test_set_overwrites_existing_key(): void
    {
        $this->memory->set('lang', 'php');
        $this->memory->set('lang', 'go');

        $this->assertSame('go', $this->memory->get('lang'));
    }

    public function test_set_preserves_created_at_on_update(): void
    {
        $this->memory->set('k', 'v1');
        $list1 = $this->memory->list();
        $createdAt1 = $list1['k']['created_at'];

        sleep(1);

        $this->memory->set('k', 'v2');
        $list2 = $this->memory->list();

        $this->assertSame($createdAt1, $list2['k']['created_at']);
        $this->assertNotSame($list1['k']['updated_at'], $list2['k']['updated_at']);
    }

    public function test_delete_removes_entry_and_returns_true(): void
    {
        $this->memory->set('foo', 'bar');

        $result = $this->memory->delete('foo');

        $this->assertTrue($result);
        $this->assertNull($this->memory->get('foo'));
    }

    public function test_delete_returns_false_for_missing_key(): void
    {
        $result = $this->memory->delete('does_not_exist');

        $this->assertFalse($result);
    }

    public function test_list_returns_all_entries(): void
    {
        $this->memory->set('a', 'alpha');
        $this->memory->set('b', 'beta');

        $list = $this->memory->list();

        $this->assertArrayHasKey('a', $list);
        $this->assertArrayHasKey('b', $list);
        $this->assertSame('alpha', $list['a']['value']);
    }

    public function test_search_matches_by_key(): void
    {
        $this->memory->set('database_host', 'localhost');
        $this->memory->set('cache_driver', 'redis');

        $results = $this->memory->search('database');

        $this->assertArrayHasKey('database_host', $results);
        $this->assertArrayNotHasKey('cache_driver', $results);
    }

    public function test_search_matches_by_value(): void
    {
        $this->memory->set('host', 'localhost');
        $this->memory->set('driver', 'redis');

        $results = $this->memory->search('redis');

        $this->assertArrayHasKey('driver', $results);
        $this->assertArrayNotHasKey('host', $results);
    }

    public function test_search_is_case_insensitive(): void
    {
        $this->memory->set('UPPER_KEY', 'MixedValue');

        $results = $this->memory->search('upper');
        $this->assertArrayHasKey('UPPER_KEY', $results);

        $results = $this->memory->search('mixed');
        $this->assertArrayHasKey('UPPER_KEY', $results);
    }

    public function test_search_returns_empty_array_when_no_match(): void
    {
        $this->memory->set('foo', 'bar');

        $results = $this->memory->search('zzznomatch');

        $this->assertSame([], $results);
    }

    public function test_for_system_prompt_returns_empty_string_with_no_entries(): void
    {
        $this->assertSame('', $this->memory->forSystemPrompt());
    }

    public function test_for_system_prompt_includes_key_value_pairs(): void
    {
        $this->memory->set('preferred_language', 'PHP');

        $prompt = $this->memory->forSystemPrompt();

        $this->assertStringContainsString('preferred_language', $prompt);
        $this->assertStringContainsString('PHP', $prompt);
    }

    public function test_for_system_prompt_respects_max_chars(): void
    {
        // Fill with enough entries to exceed a small limit
        for ($i = 0; $i < 20; $i++) {
            $this->memory->set("key_{$i}", str_repeat('x', 50));
        }

        $prompt = $this->memory->forSystemPrompt(maxChars: 200);

        $this->assertLessThanOrEqual(200, strlen($prompt));
    }

    public function test_compact_removes_oldest_entries_beyond_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->memory->set("key_{$i}", "value_{$i}");
        }

        $removed = $this->memory->compact(maxEntries: 5);

        $this->assertSame(5, $removed);
        $this->assertCount(5, $this->memory->list());
    }

    public function test_compact_keeps_most_recently_updated_entries(): void
    {
        // Inject 6 entries with deterministic timestamps via reflection
        $now = time();
        $data = [];
        for ($i = 0; $i < 6; $i++) {
            $data["key_{$i}"] = [
                'value' => "value_{$i}",
                'type' => 'note',
                'updated_at' => date('c', $now + $i), // key_5 is newest
                'created_at' => date('c', $now),
            ];
        }

        $ref = new \ReflectionClass($this->memory);
        $prop = $ref->getProperty('memories');
        $prop->setAccessible(true);
        $prop->setValue($this->memory, $data);

        $this->memory->compact(maxEntries: 3);
        $list = $this->memory->list();

        $this->assertCount(3, $list);
        // key_3, key_4, key_5 have the three highest timestamps
        $this->assertArrayHasKey('key_3', $list);
        $this->assertArrayHasKey('key_4', $list);
        $this->assertArrayHasKey('key_5', $list);
        $this->assertArrayNotHasKey('key_0', $list);
    }

    public function test_compact_returns_zero_when_within_limit(): void
    {
        $this->memory->set('a', '1');
        $this->memory->set('b', '2');

        $removed = $this->memory->compact(maxEntries: 10);

        $this->assertSame(0, $removed);
    }

    public function test_data_persists_across_instances(): void
    {
        $this->memory->set('persistent_key', 'persistent_value');

        // New instance pointing at same tmp dir
        $memory2 = new SessionMemory;
        $this->assertSame('persistent_value', $memory2->get('persistent_key'));
    }

    public function test_memory_type_is_stored(): void
    {
        $this->memory->set('my_key', 'my_value', 'preference');

        $list = $this->memory->list();
        $this->assertSame('preference', $list['my_key']['type']);
    }
}
