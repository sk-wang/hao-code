<?php

namespace Tests\Feature;

use App\Services\Task\TaskManager;
use App\Tools\Task\TaskCreateTool;
use App\Tools\Task\TaskGetTool;
use App\Tools\Task\TaskListTool;
use App\Tools\Task\TaskStopTool;
use App\Tools\Task\TaskUpdateTool;
use App\Tools\ToolUseContext;
use Tests\TestCase;

class TaskToolsTest extends TestCase
{
    private ToolUseContext $context;
    private TaskManager $manager;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );

        // Create an isolated TaskManager with its own temp directory
        $this->tmpDir = sys_get_temp_dir() . '/task_tools_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->manager = new TaskManager;
        $ref = new \ReflectionClass($this->manager);
        $pathProp = $ref->getProperty('storagePath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($this->manager, $this->tmpDir);
        $tasksProp = $ref->getProperty('tasks');
        $tasksProp->setAccessible(true);
        $tasksProp->setValue($this->manager, []);

        $this->app->instance(TaskManager::class, $this->manager);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $file = $this->tmpDir . '/tasks.json';
        if (file_exists($file)) unlink($file);
        @rmdir($this->tmpDir);
    }

    // ─── TaskCreateTool ───────────────────────────────────────────────────

    public function test_create_tool_returns_task_id(): void
    {
        $tool = new TaskCreateTool;
        $result = $tool->call([
            'subject' => 'Write tests',
            'activeForm' => 'Writing tests',
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('task_', $result->output);
    }

    public function test_create_tool_output_includes_subject(): void
    {
        $tool = new TaskCreateTool;
        $result = $tool->call([
            'subject' => 'Fix the authentication bug',
            'activeForm' => 'Fixing auth',
        ], $this->context);

        $this->assertStringContainsString('Fix the authentication bug', $result->output);
    }

    public function test_create_tool_output_includes_pending_status(): void
    {
        $tool = new TaskCreateTool;
        $result = $tool->call([
            'subject' => 'New task',
            'activeForm' => 'Working',
        ], $this->context);

        $this->assertStringContainsString('pending', $result->output);
    }

    public function test_create_tool_persists_task_to_manager(): void
    {
        $tool = new TaskCreateTool;
        $tool->call([
            'subject' => 'Persisted task',
            'activeForm' => 'Persisting',
        ], $this->context);

        $tasks = $this->manager->list();
        $this->assertCount(1, $tasks);
        $this->assertSame('Persisted task', $tasks[0]->subject);
    }

    public function test_create_tool_is_not_read_only(): void
    {
        $this->assertFalse((new TaskCreateTool)->isReadOnly([]));
    }

    // ─── TaskGetTool ──────────────────────────────────────────────────────

    public function test_get_tool_returns_task_details(): void
    {
        $task = $this->manager->create('My task', 'Working on it', 'Some details');
        $tool = new TaskGetTool;

        $result = $tool->call(['id' => $task->id], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('My task', $result->output);
        $this->assertStringContainsString('Working on it', $result->output);
        $this->assertStringContainsString('Some details', $result->output);
    }

    public function test_get_tool_includes_age(): void
    {
        $task = $this->manager->create('Timed task', 'Timing');
        $tool = new TaskGetTool;

        $result = $tool->call(['id' => $task->id], $this->context);

        $this->assertStringContainsString('Age:', $result->output);
    }

    public function test_get_tool_returns_error_for_unknown_id(): void
    {
        $tool = new TaskGetTool;
        $result = $tool->call(['id' => 'task_nonexistent_xyz'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_get_tool_is_read_only(): void
    {
        $this->assertTrue((new TaskGetTool)->isReadOnly([]));
    }

    // ─── TaskListTool ─────────────────────────────────────────────────────

    public function test_list_tool_says_no_tasks_when_empty(): void
    {
        $tool = new TaskListTool;
        $result = $tool->call([], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('No tasks', $result->output);
    }

    public function test_list_tool_shows_all_tasks(): void
    {
        $this->manager->create('Task Alpha', 'Doing Alpha');
        $this->manager->create('Task Beta', 'Doing Beta');

        $result = (new TaskListTool)->call([], $this->context);

        $this->assertStringContainsString('Task Alpha', $result->output);
        $this->assertStringContainsString('Task Beta', $result->output);
    }

    public function test_list_tool_shows_task_count(): void
    {
        $this->manager->create('One', 'First');
        $this->manager->create('Two', 'Second');
        $this->manager->create('Three', 'Third');

        $result = (new TaskListTool)->call([], $this->context);

        $this->assertStringContainsString('(3)', $result->output);
    }

    public function test_list_tool_filters_by_status(): void
    {
        $t = $this->manager->create('Work', 'Working');
        $this->manager->update($t->id, 'completed');
        $this->manager->create('Pending', 'Waiting');

        $result = (new TaskListTool)->call(['status' => 'pending'], $this->context);

        $this->assertStringContainsString('Pending', $result->output);
        $this->assertStringNotContainsString('Work', $result->output);
    }

    public function test_list_tool_is_read_only(): void
    {
        $this->assertTrue((new TaskListTool)->isReadOnly([]));
    }

    // ─── TaskUpdateTool ───────────────────────────────────────────────────

    public function test_update_tool_changes_task_status(): void
    {
        $task = $this->manager->create('Update me', 'Updating');
        $tool = new TaskUpdateTool;

        $result = $tool->call([
            'id' => $task->id,
            'status' => 'in_progress',
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('in_progress', $result->output);

        $updated = $this->manager->get($task->id);
        $this->assertSame('in_progress', $updated->status);
    }

    public function test_update_tool_sets_result(): void
    {
        $task = $this->manager->create('Finishing', 'Completing');
        $tool = new TaskUpdateTool;

        $result = $tool->call([
            'id' => $task->id,
            'status' => 'completed',
            'result' => 'All tests passed',
        ], $this->context);

        $this->assertFalse($result->isError);
        $updated = $this->manager->get($task->id);
        $this->assertSame('All tests passed', $updated->result);
    }

    public function test_update_tool_returns_error_for_unknown_id(): void
    {
        $result = (new TaskUpdateTool)->call([
            'id' => 'task_unknown_999',
            'status' => 'completed',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_update_tool_is_not_read_only(): void
    {
        $this->assertFalse((new TaskUpdateTool)->isReadOnly([]));
    }

    // ─── TaskStopTool ─────────────────────────────────────────────────────

    public function test_stop_tool_marks_task_stopped(): void
    {
        $task = $this->manager->create('Long task', 'Running');
        $result = (new TaskStopTool)->call(['id' => $task->id], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('stopped', strtolower($result->output));

        $stopped = $this->manager->get($task->id);
        $this->assertSame('completed', $stopped->status);
        $this->assertSame('Stopped by user', $stopped->result);
    }

    public function test_stop_tool_returns_error_for_unknown_id(): void
    {
        $result = (new TaskStopTool)->call(['id' => 'task_no_such'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_stop_tool_is_not_read_only(): void
    {
        $this->assertFalse((new TaskStopTool)->isReadOnly([]));
    }
}
