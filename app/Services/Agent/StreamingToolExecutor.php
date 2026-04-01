<?php

namespace App\Services\Agent;

use App\Services\Agent\ToolOrchestrator;
use App\Tools\ToolRegistry;
use App\Tools\ToolUseContext;
use App\Tools\ToolResult;

/**
 * Executes tools as they stream in from the API, not after the full response completes.
 *
 * When a tool_use content_block_stop event arrives during streaming:
 * - Safe tools (read-only + concurrency-safe) are forked immediately via pcntl_fork
 * - Unsafe tools are queued for sequential execution after the stream ends
 *
 * After the stream completes, collectResults() waits for forked children
 * and executes queued unsafe tools, returning all results in block order.
 */
class StreamingToolExecutor
{
    /** @var array<int, array{pid: int, temp_file: string, block: array}> */
    private array $earlyPids = [];

    /** @var array<int, array> block index => tool_use block */
    private array $queuedBlocks = [];

    private bool $contextSet = false;
    private ToolUseContext $context;
    /** @var callable|null */
    private $onToolStart = null;
    /** @var callable|null */
    private $onToolComplete = null;

    public function __construct(
        private readonly ToolOrchestrator $toolOrchestrator,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    public function setContext(ToolUseContext $context, ?callable $onStart, ?callable $onComplete): void
    {
        $this->context = $context;
        $this->onToolStart = $onStart;
        $this->onToolComplete = $onComplete;
        $this->contextSet = true;
    }

    /**
     * Called when a tool_use block completes during streaming (content_block_stop).
     * Safe tools are forked immediately; unsafe tools are queued.
     */
    public function onToolBlockReady(array $block, int $index): void
    {
        if (!$this->contextSet) return;

        $tool = $this->toolRegistry->getTool($block['name']);
        $input = $block['input'] ?? [];
        $isSafe = $tool
            && $tool->isConcurrencySafe($input)
            && $tool->isReadOnly($input);

        if ($isSafe && function_exists('pcntl_fork')) {
            $this->forkTool($block, $index);
        } else {
            $this->queuedBlocks[$index] = $block;
        }
    }

    /**
     * Fork a child process to execute the tool while the parent continues streaming.
     */
    private function forkTool(array $block, int $index): void
    {
        $tempFile = sys_get_temp_dir() . '/haocode_stream_' . $index . '_' . getmypid() . '_' . $block['id'];

        $pid = pcntl_fork();
        if ($pid === -1) {
            // Fork failed, queue for sequential execution
            $this->queuedBlocks[$index] = $block;
            return;
        }

        if ($pid === 0) {
            // Child process: execute tool and write result
            $result = $this->toolOrchestrator->executeToolBlock($block, $this->context);
            file_put_contents($tempFile, serialize($result));
            exit(0);
        }

        // Parent process: record child and continue streaming
        $this->earlyPids[$index] = [
            'pid' => $pid,
            'temp_file' => $tempFile,
            'block' => $block,
        ];

        if ($this->onToolStart) {
            ($this->onToolStart)($block['name'], $block['input'] ?? []);
        }
    }

    /**
     * After the stream completes, collect all tool results.
     * Waits for early-forked safe tools, then executes queued unsafe tools.
     *
     * @return array API-format tool_result blocks in original block order
     */
    public function collectResults(): array
    {
        $results = [];

        // 1. Collect results from early-forked processes
        foreach ($this->earlyPids as $index => $info) {
            pcntl_waitpid($info['pid'], $status);
            $data = @file_get_contents($info['temp_file']);
            $result = $data !== false ? @unserialize($data) : false;

            if ($result === false) {
                $result = [
                    'tool_use_id' => $info['block']['id'],
                    'content' => 'Failed to read streaming tool result',
                    'is_error' => true,
                ];
            }

            @unlink($info['temp_file']);
            $results[$index] = $result;

            if ($this->onToolComplete) {
                $toolResult = ToolResult::success($result['content'] ?? '');
                ($this->onToolComplete)($info['block']['name'], $toolResult);
            }
        }

        // 2. Execute queued unsafe tools sequentially
        foreach ($this->queuedBlocks as $index => $block) {
            $results[$index] = $this->toolOrchestrator->executeToolBlock(
                $block,
                $this->context,
                $this->onToolStart,
                $this->onToolComplete,
            );
        }

        // Sort by original block index and re-index
        ksort($results);
        return array_values($results);
    }

    /**
     * Whether any tools were started early during streaming.
     */
    public function hasEarlyExecutions(): bool
    {
        return !empty($this->earlyPids);
    }

    /**
     * Kill any running child processes (e.g. on stream error or abort).
     */
    public function cleanup(): void
    {
        foreach ($this->earlyPids as $info) {
            posix_kill($info['pid'], SIGKILL);
            pcntl_waitpid($info['pid'], $status); // reap zombie
            if (file_exists($info['temp_file'])) {
                @unlink($info['temp_file']);
            }
        }
        $this->earlyPids = [];
    }

    /**
     * Count of tools that were started early via fork.
     */
    public function earlyExecutionCount(): int
    {
        return count($this->earlyPids);
    }
}
