<?php

namespace App\Services\Task;

/**
 * Manages background task lifecycle with persistent state.
 */
class TaskManager
{
    private string $storagePath;

    /** @var array<string, Task> */
    private array $tasks = [];

    public function __construct()
    {
        $this->storagePath = sys_get_temp_dir() . '/haocode_tasks';
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        $this->loadTasks();
    }

    public function create(string $subject, string $activeForm, ?string $description = null): Task
    {
        return $this->createWithId(
            id: 'task_' . bin2hex(random_bytes(4)),
            subject: $subject,
            activeForm: $activeForm,
            description: $description,
        );
    }

    public function createWithId(string $id, string $subject, string $activeForm, ?string $description = null): Task
    {
        $this->loadTasks();

        $timestamp = time();
        $task = new Task(
            id: $id,
            subject: $subject,
            activeForm: $activeForm,
            description: $description,
            status: 'pending',
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );

        $this->tasks[$task->id] = $task;
        $this->persist();

        return $task;
    }

    public function get(string $id): ?Task
    {
        $this->loadTasks();

        return $this->tasks[$id] ?? null;
    }

    /**
     * @return Task[]
     */
    public function list(?string $status = null): array
    {
        $this->loadTasks();

        $tasks = array_values($this->tasks);
        if ($status) {
            $tasks = array_filter($tasks, fn($t) => $t->status === $status);
        }
        return $tasks;
    }

    public function update(string $id, string $status, ?string $result = null): ?Task
    {
        $this->loadTasks();

        $task = $this->tasks[$id] ?? null;
        if (!$task) return null;

        $this->tasks[$id] = $task->with(
            status: $status,
            result: $result,
            updatedAt: time(),
        );
        $this->persist();
        return $this->tasks[$id];
    }

    public function stop(string $id): ?Task
    {
        return $this->update($id, 'completed', 'Stopped by user');
    }

    public function remove(string $id): bool
    {
        $this->loadTasks();

        if (!isset($this->tasks[$id])) return false;
        unset($this->tasks[$id]);
        $this->persist();
        return true;
    }

    private function persist(): void
    {
        $data = [];
        foreach ($this->tasks as $id => $task) {
            $data[$id] = $task->toArray();
        }
        file_put_contents(
            $this->storagePath . '/tasks.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }

    private function loadTasks(): void
    {
        $file = $this->storagePath . '/tasks.json';
        $this->tasks = [];

        if (!file_exists($file)) return;

        $data = json_decode(file_get_contents($file), true) ?: [];
        foreach ($data as $id => $taskData) {
            $this->tasks[$id] = Task::fromArray($taskData);
        }

        // Clean up tasks older than 24 hours
        $cutoff = time() - 86400;
        $changed = false;
        foreach ($this->tasks as $id => $task) {
            if ($task->createdAt < $cutoff) {
                unset($this->tasks[$id]);
                $changed = true;
            }
        }
        if ($changed) $this->persist();
    }
}
