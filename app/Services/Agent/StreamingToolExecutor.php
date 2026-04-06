<?php

namespace App\Services\Agent;

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
 *
 * Sibling abort: When a Bash tool errors, all other running tool processes
 * are killed and pending tools receive synthetic error messages. This matches
 * claude-code's siblingAbortController behavior.
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

    /** Whether a Bash tool has errored, triggering sibling abort. */
    private bool $siblingAborted = false;
    /** Description of the tool that triggered the sibling abort. */
    private ?string $abortedByTool = null;

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
        if (isset($this->earlyPids[$index]) || isset($this->queuedBlocks[$index])) {
            return;
        }

        if (($block['input_json_error'] ?? null) !== null) {
            $this->queuedBlocks[$index] = $block;
            return;
        }

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

        // Snapshot readFileState before fork so we can detect child additions.
        $stateBefore = ToolUseContext::getReadFileStateSnapshot();

        $pid = pcntl_fork();
        if ($pid === -1) {
            // Fork failed, queue for sequential execution
            $this->queuedBlocks[$index] = $block;
            return;
        }

        if ($pid === 0) {
            // Child process: execute tool and serialize result + readFileState changes
            $result = $this->toolOrchestrator->executeToolBlock($block, $this->context);
            $childState = ToolUseContext::getReadFileStateSnapshot();
            $newEntries = array_diff_key($childState, $stateBefore);
            $payload = ['result' => $result, 'readState' => $newEntries];
            file_put_contents($tempFile, serialize($payload));
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
     * If a Bash tool errors, remaining queued tools receive synthetic errors.
     *
     * @return array API-format tool_result blocks in original block order
     */
    public function collectResults(): array
    {
        $results = [];

        // 1. Collect results from early-forked processes and merge readFileState
        foreach ($this->earlyPids as $index => $info) {
            pcntl_waitpid($info['pid'], $status);
            $data = @file_get_contents($info['temp_file']);
            $payload = $data !== false ? @unserialize($data) : false;

            if (is_array($payload) && isset($payload['result'])) {
                // New format: result + readFileState from child
                $result = $payload['result'];
                if (!empty($payload['readState'])) {
                    ToolUseContext::mergeReadFileStateSnapshot($payload['readState']);
                }
            } elseif (is_array($payload)) {
                // Legacy format: bare result
                $result = $payload;
            } else {
                $result = [
                    'tool_use_id' => $info['block']['id'],
                    'content' => 'Failed to read streaming tool result',
                    'is_error' => true,
                ];
            }

            @unlink($info['temp_file']);
            $results[$index] = $result;

            if ($this->onToolComplete) {
                $toolResult = $this->resultArrayToToolResult($result);
                ($this->onToolComplete)($info['block']['name'], $toolResult);
            }
        }

        // 2. Execute queued unsafe tools sequentially, with sibling abort
        foreach ($this->queuedBlocks as $index => $block) {
            if ($this->siblingAborted) {
                // Sibling abort: give synthetic error to remaining tools
                $results[$index] = [
                    'tool_use_id' => $block['id'],
                    'content' => "Tool execution skipped: a sibling Bash command ({$this->abortedByTool}) failed. Fix the error and retry.",
                    'is_error' => true,
                ];
                continue;
            }

            $result = $this->toolOrchestrator->executeToolBlock(
                $block,
                $this->context,
                $this->onToolStart,
                $this->onToolComplete,
            );

            $results[$index] = $result;

            // Check for Bash tool errors → trigger sibling abort
            if ($block['name'] === 'Bash' && ($result['is_error'] ?? false)) {
                $exitCode = $result['metadata']['exitCode'] ?? null;
                // Only abort on real errors, not semantic non-errors (grep no match, etc.)
                if ($exitCode !== null && $exitCode !== 0) {
                    $this->siblingAborted = true;
                    $this->abortedByTool = $block['input']['description']
                        ?? $block['input']['command']
                        ?? 'Bash';
                    $this->killEarlyPids();
                }
            }
        }

        // Sort by original block index and re-index
        ksort($results);
        $this->earlyPids = [];
        $this->queuedBlocks = [];
        $this->siblingAborted = false;
        $this->abortedByTool = null;
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
     * Kill any running child processes (e.g. on stream error, abort, or sibling abort).
     */
    public function cleanup(): void
    {
        $this->killEarlyPids();
        $this->queuedBlocks = [];
    }

    /**
     * Count of tools that were started early via fork.
     */
    public function earlyExecutionCount(): int
    {
        return count($this->earlyPids);
    }

    /**
     * Kill all early-forked child processes and clean up temp files.
     */
    private function killEarlyPids(): void
    {
        foreach ($this->earlyPids as $info) {
            if (function_exists('posix_kill')) {
                posix_kill($info['pid'], SIGKILL);
            }
            if (function_exists('pcntl_waitpid')) {
                pcntl_waitpid($info['pid'], $status); // reap zombie
            }
            if (file_exists($info['temp_file'])) {
                @unlink($info['temp_file']);
            }
        }
        $this->earlyPids = [];
    }

    private function resultArrayToToolResult(array $result): ToolResult
    {
        return new ToolResult(
            output: (string) ($result['content'] ?? ''),
            isError: (bool) ($result['is_error'] ?? false),
        );
    }
}
