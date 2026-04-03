<?php

namespace Tests\Feature;

use App\Services\Session\SessionManager;
use Tests\TestCase;

class SessionManagerTest extends TestCase
{
    private string $tmpDir;
    private SessionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/session_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->manager = new SessionManager;
        // Redirect session path to our temp dir
        $ref = new \ReflectionClass($this->manager);
        $prop = $ref->getProperty('sessionPath');
        $prop->setAccessible(true);
        $prop->setValue($this->manager, $this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.jsonl') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ─── getSessionId ─────────────────────────────────────────────────────

    public function test_session_id_is_non_empty_string(): void
    {
        $id = $this->manager->getSessionId();
        $this->assertNotEmpty($id);
        $this->assertIsString($id);
    }

    public function test_session_id_contains_date_prefix(): void
    {
        $id = $this->manager->getSessionId();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_/', $id);
    }

    public function test_each_manager_gets_unique_session_id(): void
    {
        $m1 = $this->manager;
        $m2 = new SessionManager;
        $this->assertNotSame($m1->getSessionId(), $m2->getSessionId());
    }

    // ─── setTitle / getTitle ──────────────────────────────────────────────

    public function test_title_is_null_initially(): void
    {
        $this->assertNull($this->manager->getTitle());
    }

    public function test_set_and_get_title(): void
    {
        $this->manager->setTitle('My Session');
        $this->assertSame('My Session', $this->manager->getTitle());
    }

    public function test_set_title_records_entry_to_file(): void
    {
        $this->manager->setTitle('Test Title');

        $files = glob($this->tmpDir . '/*.jsonl');
        $this->assertNotEmpty($files);

        $lines = file($files[0]);
        $found = false;
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (($entry['type'] ?? '') === 'session_title' && $entry['title'] === 'Test Title') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'session_title entry should be recorded');
    }

    // ─── extractTitleFromEntries ──────────────────────────────────────────

    public function test_extract_title_from_entries_returns_title(): void
    {
        $entries = [
            ['type' => 'user_message', 'content' => 'hello'],
            ['type' => 'session_title', 'title' => 'Found Title'],
        ];

        $title = SessionManager::extractTitleFromEntries($entries);
        $this->assertSame('Found Title', $title);
    }

    public function test_extract_title_returns_null_when_no_title_entry(): void
    {
        $entries = [
            ['type' => 'user_message', 'content' => 'hello'],
            ['type' => 'assistant_turn', 'message' => []],
        ];

        $this->assertNull(SessionManager::extractTitleFromEntries($entries));
    }

    public function test_extract_title_returns_null_on_empty_entries(): void
    {
        $this->assertNull(SessionManager::extractTitleFromEntries([]));
    }

    public function test_extract_title_finds_first_occurrence(): void
    {
        $entries = [
            ['type' => 'session_title', 'title' => 'First'],
            ['type' => 'session_title', 'title' => 'Second'],
        ];

        $this->assertSame('First', SessionManager::extractTitleFromEntries($entries));
    }

    // ─── recordEntry ─────────────────────────────────────────────────────

    public function test_record_entry_creates_jsonl_file(): void
    {
        $this->manager->recordEntry(['type' => 'test', 'data' => 'value']);

        $files = glob($this->tmpDir . '/*.jsonl');
        $this->assertCount(1, $files);
    }

    public function test_record_entry_appends_valid_json(): void
    {
        $this->manager->recordEntry(['type' => 'a', 'value' => 1]);
        $this->manager->recordEntry(['type' => 'b', 'value' => 2]);

        $files = glob($this->tmpDir . '/*.jsonl');
        $lines = array_filter(array_map('trim', file($files[0])));
        $this->assertCount(2, $lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertNotNull($decoded);
        }
    }

    public function test_record_entry_includes_timestamp(): void
    {
        $this->manager->recordEntry(['type' => 'ping']);

        $files = glob($this->tmpDir . '/*.jsonl');
        $entry = json_decode(file($files[0])[0], true);
        $this->assertArrayHasKey('timestamp', $entry);
    }

    public function test_record_entry_includes_session_id(): void
    {
        $this->manager->recordEntry(['type' => 'ping']);

        $files = glob($this->tmpDir . '/*.jsonl');
        $entry = json_decode(file($files[0])[0], true);
        $this->assertSame($this->manager->getSessionId(), $entry['session_id']);
    }

    // ─── recordUserMessage ────────────────────────────────────────────────

    public function test_record_user_message_stores_content(): void
    {
        $this->manager->recordUserMessage('Hello world');

        $files = glob($this->tmpDir . '/*.jsonl');
        $entry = json_decode(file($files[0])[0], true);
        $this->assertSame('user_message', $entry['type']);
        $this->assertSame('Hello world', $entry['content']);
    }

    // ─── loadSession ─────────────────────────────────────────────────────

    public function test_load_session_returns_empty_array_for_unknown_session(): void
    {
        $entries = $this->manager->loadSession('nonexistent_session_id');
        $this->assertSame([], $entries);
    }

    public function test_load_session_by_exact_id(): void
    {
        $this->manager->recordUserMessage('test message');

        $entries = $this->manager->loadSession($this->manager->getSessionId());
        $this->assertNotEmpty($entries);
        $this->assertSame('user_message', $entries[0]['type']);
    }

    public function test_load_session_entries_are_decoded_arrays(): void
    {
        $this->manager->recordEntry(['type' => 'test', 'nested' => ['a' => 1]]);

        $entries = $this->manager->loadSession($this->manager->getSessionId());
        $this->assertIsArray($entries[0]);
        $this->assertSame('test', $entries[0]['type']);
    }

    // ─── recordTurn ───────────────────────────────────────────────────────

    public function test_record_turn_stores_assistant_turn_type(): void
    {
        $this->manager->recordTurn(
            ['role' => 'assistant', 'content' => 'Hello'],
            [['tool_use_id' => 'x', 'content' => 'ok']]
        );

        $files = glob($this->tmpDir . '/*.jsonl');
        $entry = json_decode(file($files[0])[0], true);
        $this->assertSame('assistant_turn', $entry['type']);
    }
}
