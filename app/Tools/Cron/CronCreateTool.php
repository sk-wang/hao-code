<?php

namespace App\Tools\Cron;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class CronCreateTool extends BaseTool
{
    public function name(): string
    {
        return 'CronCreate';
    }

    public function description(): string
    {
        return 'Schedule a prompt to run at a future time, either once or recurring. Cron format: minute hour day-of-month month day-of-week.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'cron' => [
                    'type' => 'string',
                    'description' => 'Standard 5-field cron expression in local time (e.g. "*/5 * * * *")',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'The prompt to execute at each fire time',
                ],
                'recurring' => [
                    'type' => 'boolean',
                    'description' => 'true = fire on every cron match, false = fire once at next match',
                ],
                'durable' => [
                    'type' => 'boolean',
                    'description' => 'true = persist to disk and survive restarts',
                ],
            ],
            'required' => ['cron', 'prompt'],
        ], [
            'cron' => 'required|string',
            'prompt' => 'required|string',
            'recurring' => 'nullable|boolean',
            'durable' => 'nullable|boolean',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $cron = $input['cron'];
        $prompt = $input['prompt'];
        $recurring = $input['recurring'] ?? true;
        $durable = $input['durable'] ?? false;

        // Validate cron expression (5 fields)
        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) !== 5) {
            return ToolResult::error("Invalid cron: must have 5 fields. Got: {$cron}");
        }

        $jobId = 'cron_' . bin2hex(random_bytes(4));

        $job = [
            'id' => $jobId,
            'cron' => $cron,
            'prompt' => $prompt,
            'recurring' => $recurring,
            'durable' => $durable,
            'created_at' => date('c'),
            'last_fired' => null,
            'fire_count' => 0,
            'status' => 'active',
        ];

        CronScheduler::addJob($job);

        if ($durable) {
            $this->persistJob($job);
        }

        $cadence = $this->describeCadence($cron);
        $expiry = $recurring ? ' Auto-expires after 7 days.' : '';
        $persistence = $durable ? ' Persisted to disk.' : '';

        return ToolResult::success(
            "Scheduled: {$jobId}\n" .
            "Cadence: {$cadence}\n" .
            "Prompt: {$prompt}\n" .
            "Recurring: " . ($recurring ? 'yes' : 'no (one-shot)') . ".{$expiry}{$persistence}\n" .
            "Cancel with CronDelete: {$jobId}"
        );
    }

    private function describeCadence(string $cron): string
    {
        $parts = preg_split('/\s+/', trim($cron));
        return match (true) {
            str_starts_with($parts[0], '*/') && $parts[1] === '*' => "every {$this->parseInterval($parts[0])}",
            $parts[0] !== '*' && $parts[1] !== '*' && $parts[4] === '*' => "daily at {$parts[1]}:{$parts[0]}",
            $parts[0] !== '*' && $parts[1] !== '*' && $parts[4] !== '*' => "at {$parts[1]}:{$parts[0]} on day {$parts[4]}",
            default => $cron,
        };
    }

    private function parseInterval(string $field): string
    {
        if (preg_match('/\*\/(\d+)/', $field, $m)) {
            $n = (int) $m[1];
            if ($n < 60) return "{$n} minutes";
            if ($n % 60 === 0) return ($n / 60) . ' hours';
            return "{$n} minutes";
        }
        return $field;
    }

    private function persistJob(array $job): void
    {
        $path = ($_SERVER['HOME'] ?? '~') . '/.haocode/scheduled_tasks.json';
        $jobs = [];
        if (file_exists($path)) {
            $jobs = json_decode(file_get_contents($path), true) ?: [];
        }
        $jobs[$job['id']] = $job;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($path, json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function isReadOnly(array $input): bool { return false; }
    public function isConcurrencySafe(array $input): bool { return true; }
}
