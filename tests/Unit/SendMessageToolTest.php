<?php

namespace Tests\Unit;

use App\Services\Agent\BackgroundAgentManager;
use App\Tools\Agent\SendMessageTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class SendMessageToolTest extends TestCase
{
    private string $tempDir;
    private BackgroundAgentManager $manager;
    private SendMessageTool $tool;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/haocode_send_message_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->manager = new BackgroundAgentManager($this->tempDir);
        $this->tool = new SendMessageTool($this->manager);
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'session-main');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    public function test_it_queues_a_message_for_a_running_agent(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $this->manager->markRunning('agent_demo');

        $result = $this->tool->call([
            'to' => 'agent_demo',
            'message' => 'Check the failing tests',
            'summary' => 'new task',
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Queued message', $result->output);
        $this->assertSame(1, $this->manager->get('agent_demo')['pending_messages']);
    }

    public function test_it_errors_when_agent_does_not_exist(): void
    {
        $result = $this->tool->call([
            'to' => 'agent_missing',
            'message' => 'Hello?',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_it_errors_when_agent_has_already_completed(): void
    {
        $this->manager->create('agent_demo', 'Inspect repo', 'Explore');
        $this->manager->markCompleted('agent_demo', 'Done');

        $result = $this->tool->call([
            'to' => 'agent_demo',
            'message' => 'One more thing',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('no longer running', $result->output);
    }
}
