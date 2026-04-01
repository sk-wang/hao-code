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
        $task = new Task(
            id: 'task_' . bin2hex(random_bytes(4)),
            subject: $subject,
            activeForm: $activeForm,
            description: $description,
            status: 'pending',
            createdAt: time(),
            updatedAt: time(),
        );

        $this->tasks[$task->id] = $task;
        $this->persist();

        return $task;
    }

    public function get(string $id): ?Task
    {
        return $this->tasks[$id] ?? null;
    }

    /**
     * @return Task[]
     */
    public function list(string $status = null): array
    {
        $tasks = array_values($this->tasks);
        if ($status) {
            $tasks = array_filter($tasks, fn($t) => $t->status === $status);
        }
        return $tasks;
    }

    public function update(string $id, string $status, ?string $result = null): ?Task
    {
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
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function loadTasks(): void
    {
        $file = $this->storagePath . '/tasks.json';
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
