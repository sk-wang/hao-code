<?php

namespace Tests\Unit;

use App\Services\Task\Task;
use App\Services\Task\TaskManager;
use PHPUnit\Framework\TestCase;

class TaskManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Use a unique temp directory per test to isolate state
        $this->tempDir = sys_get_temp_dir() . '/haocode_tasks_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        // Remove all test task files
        if (is_dir($this->tempDir)) {
            $file = $this->tempDir . '/tasks.json';
            if (file_exists($file)) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    private function makeManager(): TaskManager
    {
        // Override storage path via reflection to use our isolated temp dir
        $manager = new TaskManager;
        $ref = new \ReflectionClass($manager);
        $prop = $ref->getProperty('storagePath');
        $prop->setAccessible(true);
        $prop->setValue($manager, $this->tempDir);

        // Ensure directory exists
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        // Clear any tasks loaded from default location
        $tasks = $ref->getProperty('tasks');
        $tasks->setAccessible(true);
        $tasks->setValue($manager, []);

        return $manager;
    }

    // ─── create ───────────────────────────────────────────────────────────

    public function test_create_returns_task_with_correct_fields(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Fix bug', 'Fixing bug', 'Optional description');

        $this->assertStringStartsWith('task_', $task->id);
        $this->assertSame('Fix bug', $task->subject);
        $this->assertSame('Fixing bug', $task->activeForm);
        $this->assertSame('Optional description', $task->description);
        $this->assertSame('pending', $task->status);
        $this->assertNull($task->result);
        $this->assertGreaterThan(0, $task->createdAt);
    }

    public function test_create_without_description(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Task', 'Tasking');

        $this->assertNull($task->description);
    }

    public function test_created_task_is_retrievable_via_get(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Do X', 'Doing X');

        $retrieved = $manager->get($task->id);

        $this->assertNotNull($retrieved);
        $this->assertSame($task->id, $retrieved->id);
    }

    // ─── get ──────────────────────────────────────────────────────────────

    public function test_get_returns_null_for_unknown_id(): void
    {
        $manager = $this->makeManager();
        $this->assertNull($manager->get('nonexistent_task'));
    }

    // ─── list ─────────────────────────────────────────────────────────────

    public function test_list_returns_all_tasks_without_filter(): void
    {
        $manager = $this->makeManager();
        $manager->create('Task A', 'Doing A');
        $manager->create('Task B', 'Doing B');

        $tasks = $manager->list();

        $this->assertCount(2, $tasks);
    }

    public function test_list_filters_by_status(): void
    {
        $manager = $this->makeManager();
        $t1 = $manager->create('Task 1', 'Doing 1');
        $manager->create('Task 2', 'Doing 2');
        $manager->update($t1->id, 'completed');

        $pending = $manager->list('pending');
        $completed = $manager->list('completed');

        $this->assertCount(1, $pending);
        $this->assertCount(1, $completed);
        $this->assertSame('completed', $completed[0]->status);
    }

    public function test_list_returns_empty_array_when_no_tasks(): void
    {
        $manager = $this->makeManager();
        $this->assertSame([], $manager->list());
    }

    // ─── update ───────────────────────────────────────────────────────────

    public function test_update_changes_status(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Work', 'Working');

        $updated = $manager->update($task->id, 'in_progress');

        $this->assertNotNull($updated);
        $this->assertSame('in_progress', $updated->status);
    }

    public function test_update_sets_result(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Work', 'Working');

        $updated = $manager->update($task->id, 'completed', 'All done!');

        $this->assertSame('All done!', $updated->result);
    }

    public function test_update_returns_null_for_unknown_id(): void
    {
        $manager = $this->makeManager();
        $this->assertNull($manager->update('nonexistent', 'completed'));
    }

    public function test_update_sets_updated_at_timestamp(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Work', 'Working');
        $beforeUpdate = time();

        $updated = $manager->update($task->id, 'in_progress');

        $this->assertGreaterThanOrEqual($beforeUpdate, $updated->updatedAt);
    }

    // ─── stop ─────────────────────────────────────────────────────────────

    public function test_stop_marks_task_as_completed_with_message(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Long task', 'Running');

        $stopped = $manager->stop($task->id);

        $this->assertNotNull($stopped);
        $this->assertSame('completed', $stopped->status);
        $this->assertSame('Stopped by user', $stopped->result);
    }

    public function test_stop_returns_null_for_unknown_id(): void
    {
        $manager = $this->makeManager();
        $this->assertNull($manager->stop('no_such_task'));
    }

    // ─── remove ───────────────────────────────────────────────────────────

    public function test_remove_deletes_task(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Temp task', 'Temping');

        $result = $manager->remove($task->id);

        $this->assertTrue($result);
        $this->assertNull($manager->get($task->id));
    }

    public function test_remove_returns_false_for_unknown_id(): void
    {
        $manager = $this->makeManager();
        $this->assertFalse($manager->remove('no_such_task'));
    }

    // ─── Persistence ──────────────────────────────────────────────────────

    public function test_tasks_persist_to_json_file(): void
    {
        $manager = $this->makeManager();
        $task = $manager->create('Persist me', 'Persisting');

        $this->assertFileExists($this->tempDir . '/tasks.json');

        $data = json_decode(file_get_contents($this->tempDir . '/tasks.json'), true);
        $this->assertArrayHasKey($task->id, $data);
        $this->assertSame('Persist me', $data[$task->id]['subject']);
    }

    public function test_tasks_survive_across_manager_instances(): void
    {
        $manager1 = $this->makeManager();
        $task = $manager1->create('Survive me', 'Surviving');

        // Create a second manager pointing to same directory
        $manager2 = $this->makeManager();

        // Load from file by re-initializing
        $ref = new \ReflectionClass($manager2);
        $loadMethod = $ref->getMethod('loadTasks');
        $loadMethod->setAccessible(true);
        $loadMethod->invoke($manager2);

        $retrieved = $manager2->get($task->id);
        $this->assertNotNull($retrieved);
        $this->assertSame('Survive me', $retrieved->subject);
    }

    // ─── Auto-cleanup of old tasks ─────────────────────────────────────────

    public function test_tasks_older_than_24h_are_cleaned_on_load(): void
    {
        // Write a task file with an old createdAt
        $oldTime = time() - 90000; // 25 hours ago
        $data = [
            'task_old' => [
                'id' => 'task_old',
                'subject' => 'Old task',
                'activeForm' => 'Old',
                'description' => null,
                'status' => 'pending',
                'result' => null,
                'createdAt' => $oldTime,
                'updatedAt' => $oldTime,
            ],
        ];

        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/tasks.json', json_encode($data));

        $manager = $this->makeManager();
        $ref = new \ReflectionClass($manager);
        $tasksRef = $ref->getProperty('tasks');
        $tasksRef->setAccessible(true);
        $tasksRef->setValue($manager, []);

        $loadMethod = $ref->getMethod('loadTasks');
        $loadMethod->setAccessible(true);
        $loadMethod->invoke($manager);

        $this->assertNull($manager->get('task_old'));
    }

    public function test_recent_tasks_are_not_cleaned(): void
    {
        $recentTime = time() - 3600; // 1 hour ago
        $data = [
            'task_recent' => [
                'id' => 'task_recent',
                'subject' => 'Recent task',
                'activeForm' => 'Recent',
                'description' => null,
                'status' => 'pending',
                'result' => null,
                'createdAt' => $recentTime,
                'updatedAt' => $recentTime,
            ],
        ];

        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/tasks.json', json_encode($data));

        $manager = $this->makeManager();
        $ref = new \ReflectionClass($manager);
        $tasksRef = $ref->getProperty('tasks');
        $tasksRef->setAccessible(true);
        $tasksRef->setValue($manager, []);

        $loadMethod = $ref->getMethod('loadTasks');
        $loadMethod->setAccessible(true);
        $loadMethod->invoke($manager);

        $this->assertNotNull($manager->get('task_recent'));
    }
}
