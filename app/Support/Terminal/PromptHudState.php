<?php

namespace App\Support\Terminal;

class PromptHudState
{
    /** @var array<int, array{name: string, target: string|null, status: string}> */
    private array $tools = [];

    /** @var array{event: string, label: string, detail: string|null}|null */
    private ?array $turn = null;

    /** @var array<int, array{content: string, status: string}> */
    private array $todos = [];

    /** @var array<string, int> */
    private array $taskIdToIndex = [];

    private const MAX_TOOL_HISTORY = 20;

    /** @var array<int, string> */
    private const EXCLUDED_TOOLS = [
        'Agent',
        'Task',
        'TaskCreate',
        'TaskGet',
        'TaskList',
        'TaskStop',
        'TaskUpdate',
        'TodoWrite',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function hydrateFromSessionEntries(array $entries): void
    {
        $this->reset();

        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) !== 'assistant_turn') {
                continue;
            }

            $message = $entry['message'] ?? null;
            $content = is_array($message) && is_array($message['content'] ?? null)
                ? $message['content']
                : [];
            $results = is_array($entry['tool_results'] ?? null) ? $entry['tool_results'] : [];
            $resultsById = [];

            foreach ($results as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $toolUseId = $result['tool_use_id'] ?? null;
                if (is_string($toolUseId) && $toolUseId !== '') {
                    $resultsById[$toolUseId] = $result;
                }
            }

            foreach ($content as $block) {
                if (! is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }

                $toolName = $block['name'] ?? null;
                if (! is_string($toolName) || $toolName === '') {
                    continue;
                }

                $toolUseId = is_string($block['id'] ?? null) ? $block['id'] : null;
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                $result = $toolUseId !== null ? ($resultsById[$toolUseId] ?? null) : null;

                $this->ingestToolUse($toolName, $input, $result, $toolUseId);
            }
        }
    }

    public function reset(): void
    {
        $this->tools = [];
        $this->turn = null;
        $this->todos = [];
        $this->taskIdToIndex = [];
    }

    public function recordTurnEvent(string $event, ?string $detail = null): void
    {
        $this->turn = [
            'event' => $event,
            'label' => $this->turnLabel($event),
            'detail' => $detail !== null && trim($detail) !== '' ? trim($detail) : null,
        ];
    }

    /**
     * @return array{event: string, label: string, detail: string|null}|null
     */
    public function summarizeTurn(): ?array
    {
        return $this->turn;
    }

    /**
     * @param  array{event: string, label: string, detail: string|null}|null  $turn
     */
    public function restoreTurnSummary(?array $turn): void
    {
        $this->turn = $turn;
    }

    /**
     * @return array{
     *   running: array<int, array{name: string, target: string|null}>,
     *   completed: array<int, array{name: string, count: int}>
     * }
     */
    public function summarizeTools(): array
    {
        $running = [];
        $counts = [];

        foreach ($this->tools as $tool) {
            if ($tool['status'] === 'running') {
                $running[] = [
                    'name' => $tool['name'],
                    'target' => $tool['target'],
                ];

                continue;
            }

            $counts[$tool['name']] = ($counts[$tool['name']] ?? 0) + 1;
        }

        arsort($counts);

        $completed = [];
        foreach (array_slice($counts, 0, 4, true) as $name => $count) {
            $completed[] = [
                'name' => $name,
                'count' => $count,
            ];
        }

        return [
            'running' => array_slice($running, -2),
            'completed' => $completed,
        ];
    }

    /**
     * @return array{current: string|null, completed: int, total: int, all_completed: bool}|null
     */
    public function summarizeTodos(): ?array
    {
        if ($this->todos === []) {
            return null;
        }

        $completed = count(array_filter(
            $this->todos,
            static fn (array $todo): bool => ($todo['status'] ?? null) === 'completed',
        ));
        $inProgress = null;

        foreach ($this->todos as $todo) {
            if (($todo['status'] ?? null) === 'in_progress') {
                $inProgress = $todo['content'] ?? null;
                break;
            }
        }

        return [
            'current' => is_string($inProgress) && $inProgress !== '' ? $inProgress : null,
            'completed' => $completed,
            'total' => count($this->todos),
            'all_completed' => $completed === count($this->todos) && $completed > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $result
     */
    private function ingestToolUse(string $toolName, array $input, ?array $result, ?string $toolUseId): void
    {
        if ($toolName === 'TodoWrite') {
            $this->replaceTodos($input['todos'] ?? []);

            return;
        }

        if ($toolName === 'TaskCreate') {
            $this->recordTaskCreate($input, $result, $toolUseId);

            return;
        }

        if ($toolName === 'TaskUpdate') {
            $this->recordTaskUpdate($input);

            return;
        }

        if ($toolName === 'TaskStop') {
            $this->recordTaskStop($input);

            return;
        }

        if (in_array($toolName, self::EXCLUDED_TOOLS, true)) {
            return;
        }

        $this->tools[] = [
            'name' => $toolName,
            'target' => $this->extractTarget($toolName, $input),
            'status' => ($result['is_error'] ?? false) ? 'error' : 'completed',
        ];

        $this->tools = array_slice($this->tools, -self::MAX_TOOL_HISTORY);
    }

    /**
     * @param  mixed  $todos
     */
    private function replaceTodos(mixed $todos): void
    {
        if (! is_array($todos)) {
            return;
        }

        $normalized = [];
        foreach ($todos as $todo) {
            if (! is_array($todo)) {
                continue;
            }

            $content = trim((string) ($todo['content'] ?? ''));
            $status = $this->normalizeTodoStatus($todo['status'] ?? null);

            if ($content === '' || $status === null) {
                continue;
            }

            $normalized[] = [
                'content' => $content,
                'status' => $status,
            ];
        }

        $this->todos = $normalized;
        $this->taskIdToIndex = [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $result
     */
    private function recordTaskCreate(array $input, ?array $result, ?string $fallbackTaskId): void
    {
        $content = $this->resolveTaskContent($input);
        if ($content === '') {
            return;
        }

        $status = $this->normalizeTodoStatus($input['status'] ?? null) ?? 'pending';
        $this->todos[] = [
            'content' => $content,
            'status' => $status,
        ];

        $taskId = $this->extractCreatedTaskId($result)
            ?? $this->stringValue($input['id'] ?? null)
            ?? $this->stringValue($input['taskId'] ?? null)
            ?? $fallbackTaskId;

        if ($taskId !== null && $taskId !== '') {
            $this->taskIdToIndex[$taskId] = count($this->todos) - 1;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function recordTaskUpdate(array $input): void
    {
        $taskId = $this->stringValue($input['id'] ?? null) ?? $this->stringValue($input['taskId'] ?? null);
        if ($taskId === null) {
            return;
        }

        $index = $this->taskIdToIndex[$taskId] ?? null;
        if (! is_int($index) || ! isset($this->todos[$index])) {
            return;
        }

        $status = $this->normalizeTodoStatus($input['status'] ?? null);
        if ($status !== null) {
            $this->todos[$index]['status'] = $status;
        }

        $content = $this->resolveTaskContent($input);
        if ($content !== '') {
            $this->todos[$index]['content'] = $content;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function recordTaskStop(array $input): void
    {
        $taskId = $this->stringValue($input['id'] ?? null);
        if ($taskId === null) {
            return;
        }

        $index = $this->taskIdToIndex[$taskId] ?? null;
        if (! is_int($index) || ! isset($this->todos[$index])) {
            return;
        }

        $this->todos[$index]['status'] = 'completed';
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function extractCreatedTaskId(?array $result): ?string
    {
        $content = is_string($result['content'] ?? null) ? $result['content'] : '';
        if ($content === '') {
            return null;
        }

        if (preg_match('/Created task:\s*([^\s]+)/', $content, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveTaskContent(array $input): string
    {
        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject !== '') {
            return $subject;
        }

        return trim((string) ($input['description'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function extractTarget(string $toolName, array $input): ?string
    {
        return match ($toolName) {
            'Read', 'Write', 'Edit' => $this->stringValue($input['file_path'] ?? null) ?? $this->stringValue($input['path'] ?? null),
            'Glob', 'Grep' => $this->stringValue($input['pattern'] ?? null),
            'Bash' => $this->truncate($this->stringValue($input['command'] ?? null) ?? '', 30),
            'WebFetch' => $this->truncate($this->stringValue($input['url'] ?? null) ?? '', 30),
            default => null,
        };
    }

    private function normalizeTodoStatus(mixed $status): ?string
    {
        if (! is_string($status)) {
            return null;
        }

        return match ($status) {
            'pending', 'not_started' => 'pending',
            'in_progress', 'running' => 'in_progress',
            'completed', 'complete', 'done' => 'completed',
            default => null,
        };
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, max(1, $max - 1)).'…';
    }

    private function turnLabel(string $event): string
    {
        return match ($event) {
            'turn.started' => 'Turn started',
            'tool.started' => 'Tool running',
            'tool.completed' => 'Tool finished',
            'plan.updated' => 'Plan updated',
            'turn.completed' => 'Turn completed',
            'turn.failed' => 'Turn failed',
            default => $event,
        };
    }
}
