<?php

namespace Tests\Unit;

use App\Services\Session\SessionStatsService;
use Tests\TestCase;

class SessionStatsServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/haocode_session_stats_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
        config(['haocode.session_path' => $this->tmpDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*.jsonl') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function test_overview_aggregates_session_metrics(): void
    {
        $today = date('Y-m-d');
        file_put_contents($this->tmpDir.'/session-a.jsonl', implode("\n", [
            json_encode(['timestamp' => $today.'T10:00:00+08:00', 'session_id' => 'session-a', 'type' => 'session_title', 'title' => 'Session A']),
            json_encode(['timestamp' => $today.'T10:01:00+08:00', 'session_id' => 'session-a', 'type' => 'user_message', 'content' => 'hello']),
            json_encode(['timestamp' => $today.'T10:02:00+08:00', 'session_id' => 'session-a', 'type' => 'assistant_turn', 'message' => ['role' => 'assistant', 'content' => 'hi'], 'tool_results' => [['tool_use_id' => '1', 'content' => 'ok']]]),
        ])."\n");

        file_put_contents($this->tmpDir.'/session-b.jsonl', implode("\n", [
            json_encode(['timestamp' => $today.'T11:00:00+08:00', 'session_id' => 'session-b', 'type' => 'session_title', 'title' => 'Session B']),
            json_encode(['timestamp' => $today.'T11:00:30+08:00', 'session_id' => 'session-b', 'type' => 'session_branch', 'source_session_id' => 'session-a']),
            json_encode(['timestamp' => $today.'T11:01:00+08:00', 'session_id' => 'session-b', 'type' => 'user_message', 'content' => 'follow-up']),
            json_encode(['timestamp' => $today.'T11:02:00+08:00', 'session_id' => 'session-b', 'type' => 'assistant_turn', 'message' => ['role' => 'assistant', 'content' => 'done'], 'tool_results' => []]),
        ])."\n");

        $service = new SessionStatsService;
        $overview = $service->getOverview('session-b');

        $this->assertSame(2, $overview['sessions_count']);
        $this->assertSame(2, $overview['sessions_today']);
        $this->assertSame(2, $overview['total_user_messages']);
        $this->assertSame(2, $overview['total_assistant_turns']);
        $this->assertSame(1, $overview['total_tool_results']);
        $this->assertSame('session-b', $overview['sessions'][0]['session_id']);
        $this->assertSame('session-a', $overview['sessions'][0]['branch_source']);
    }

    public function test_get_session_returns_zeroed_stats_for_unknown_session(): void
    {
        $service = new SessionStatsService;
        $session = $service->getSession('missing');

        $this->assertSame('missing', $session['session_id']);
        $this->assertSame(0, $session['user_messages']);
        $this->assertNull($session['title']);
    }
}
