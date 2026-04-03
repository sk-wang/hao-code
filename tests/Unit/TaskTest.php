<?php

namespace Tests\Unit;

use App\Services\Task\Task;
use PHPUnit\Framework\TestCase;

class TaskTest extends TestCase
{
    private function makeTask(array $overrides = []): Task
    {
        return new Task(
            id: $overrides['id'] ?? 'task_abc',
            subject: $overrides['subject'] ?? 'Do something',
            activeForm: $overrides['activeForm'] ?? 'Doing something',
            description: $overrides['description'] ?? null,
            status: $overrides['status'] ?? 'pending',
            result: $overrides['result'] ?? null,
            createdAt: $overrides['createdAt'] ?? 1000,
            updatedAt: $overrides['updatedAt'] ?? 1000,
        );
    }

    // ─── Constructor / accessors ───────────────────────────────────────────

    public function test_properties_are_accessible(): void
    {
        $task = $this->makeTask(['id' => 'task_001', 'status' => 'in_progress']);

        $this->assertSame('task_001', $task->id);
        $this->assertSame('Do something', $task->subject);
        $this->assertSame('Doing something', $task->activeForm);
        $this->assertSame('in_progress', $task->status);
        $this->assertNull($task->result);
    }

    public function test_description_defaults_to_null(): void
    {
        $task = $this->makeTask();
        $this->assertNull($task->description);
    }

    // ─── with() ───────────────────────────────────────────────────────────

    public function test_with_status_returns_new_task_with_updated_status(): void
    {
        $task = $this->makeTask(['status' => 'pending']);
        $updated = $task->with(status: 'completed');

        $this->assertSame('completed', $updated->status);
        $this->assertSame('pending', $task->status); // original unchanged
    }

    public function test_with_result_returns_new_task_with_result(): void
    {
        $task = $this->makeTask();
        $updated = $task->with(result: 'Done!');

        $this->assertSame('Done!', $updated->result);
        $this->assertNull($task->result); // original unchanged
    }

    public function test_with_preserves_immutable_fields(): void
    {
        $task = $this->makeTask([
            'id' => 'task_xyz',
            'subject' => 'My subject',
            'activeForm' => 'Doing it',
            'description' => 'Details here',
            'createdAt' => 5000,
        ]);

        $updated = $task->with(status: 'completed', updatedAt: 9999);

        $this->assertSame('task_xyz', $updated->id);
        $this->assertSame('My subject', $updated->subject);
        $this->assertSame('Doing it', $updated->activeForm);
        $this->assertSame('Details here', $updated->description);
        $this->assertSame(5000, $updated->createdAt);
        $this->assertSame(9999, $updated->updatedAt);
    }

    public function test_with_null_result_preserves_existing_result(): void
    {
        // Known limitation: with(result: null) cannot clear an existing result
        $task = $this->makeTask(['result' => 'previous result']);
        $updated = $task->with(result: null);

        // The existing result is preserved (null coalescing keeps old value)
        $this->assertSame('previous result', $updated->result);
    }

    public function test_with_no_args_returns_identical_copy(): void
    {
        $task = $this->makeTask(['status' => 'in_progress', 'result' => 'partial']);
        $copy = $task->with();

        $this->assertSame($task->status, $copy->status);
        $this->assertSame($task->result, $copy->result);
        $this->assertSame($task->id, $copy->id);
    }

    // ─── toArray() ────────────────────────────────────────────────────────

    public function test_to_array_contains_all_fields(): void
    {
        $task = $this->makeTask([
            'id' => 'task_abc',
            'subject' => 'Test',
            'activeForm' => 'Testing',
            'description' => 'Do tests',
            'status' => 'completed',
            'result' => 'All good',
            'createdAt' => 1000,
            'updatedAt' => 2000,
        ]);

        $arr = $task->toArray();

        $this->assertSame('task_abc', $arr['id']);
        $this->assertSame('Test', $arr['subject']);
        $this->assertSame('Testing', $arr['activeForm']);
        $this->assertSame('Do tests', $arr['description']);
        $this->assertSame('completed', $arr['status']);
        $this->assertSame('All good', $arr['result']);
        $this->assertSame(1000, $arr['createdAt']);
        $this->assertSame(2000, $arr['updatedAt']);
    }

    // ─── fromArray() ──────────────────────────────────────────────────────

    public function test_from_array_round_trips_with_to_array(): void
    {
        $task = $this->makeTask([
            'id' => 'task_round',
            'subject' => 'Round trip',
            'activeForm' => 'Rounding',
            'description' => 'Full round',
            'status' => 'in_progress',
            'result' => null,
            'createdAt' => 100,
            'updatedAt' => 200,
        ]);

        $restored = Task::fromArray($task->toArray());

        $this->assertSame($task->id, $restored->id);
        $this->assertSame($task->subject, $restored->subject);
        $this->assertSame($task->status, $restored->status);
        $this->assertSame($task->createdAt, $restored->createdAt);
    }

    public function test_from_array_applies_defaults_for_optional_fields(): void
    {
        $task = Task::fromArray([
            'id' => 'task_minimal',
            'subject' => 'Minimal',
            'activeForm' => 'Minimizing',
        ]);

        $this->assertSame('pending', $task->status);
        $this->assertNull($task->result);
        $this->assertNull($task->description);
        $this->assertSame(0, $task->createdAt);
        $this->assertSame(0, $task->updatedAt);
    }
}
