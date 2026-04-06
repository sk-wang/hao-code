<?php

namespace Tests\Unit;

use App\Services\Agent\AgentLoop;
use App\Services\Agent\ContextBuilder;
use App\Services\Agent\MessageHistory;
use App\Services\Agent\QueryEngine;
use App\Services\Agent\StreamProcessor;
use App\Services\Agent\ToolOrchestrator;
use App\Services\Compact\ContextCompactor;
use App\Services\Cost\CostTracker;
use App\Services\Hooks\HookExecutor;
use App\Services\Hooks\HookResult;
use App\Services\Permissions\PermissionChecker;
use App\Services\Session\SessionManager;
use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;
use App\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

class AgentLoopTest extends TestCase
{
    // ─── helpers ──────────────────────────────────────────────────────────

    private function makeTool(string $name, callable $call): BaseTool
    {
        return new class($name, $call) extends BaseTool {
            public function __construct(private string $n, private $fn) {}
            public function name(): string { return $this->n; }
            public function description(): string { return ''; }
            public function inputSchema(): ToolInputSchema { return ToolInputSchema::make(['type' => 'object'], []); }
            public function call(array $input, ToolUseContext $ctx): ToolResult { return ($this->fn)($input, $ctx); }
        };
    }

    private function makeLoop(
        QueryEngine $queryEngine,
        ?ToolRegistry $registry = null,
        ?ContextCompactor $compactor = null,
        ?SessionManager $sessionManager = null,
    ): AgentLoop
    {
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $sessionManager ??= $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $compactor ??= $this->createMock(ContextCompactor::class);
        if (!$compactor instanceof \PHPUnit\Framework\MockObject\MockObject) {
            // real object, do nothing
        } else {
            $compactor->method('shouldAutoCompact')->willReturn(false);
        }

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);

        return new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: new CostTracker(999.0, 9999.0),
            toolRegistry: $registry ?? new ToolRegistry,
            hookExecutor: $hookExecutor,
        );
    }

    private function makePlainEndTurnProcessor(string $text): StreamProcessor
    {
        return $this->makePlainTextProcessor($text);
    }

    // ─── simple end_turn returns text ─────────────────────────────────────

    public function test_simple_end_turn_returns_accumulated_text(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('Hello there'));

        $loop = $this->makeLoop($qe);
        $result = $loop->run('hi');
        $this->assertSame('Hello there', $result);
    }

    public function test_simple_end_turn_records_final_assistant_turn(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('Hello there'));

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->expects($this->once())
            ->method('recordTurn')
            ->with(
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello there']]],
                [],
            );

        $loop = $this->makeLoop($qe, sessionManager: $sessionManager);
        $loop->run('hi');
    }

    public function test_incomplete_plain_text_response_is_retried_before_returning(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncompletePlainTextProcessor('已拿到部分结果，请继续补全剩余内容。'),
                $this->makePlainTextProcessor('已完成'),
            );

        $loop = $this->makeLoop($qe);
        $result = $loop->run('继续');

        $this->assertSame('已完成', $result);
        $messages = $loop->getMessageHistory()->getMessages();
        $this->assertCount(4, $messages);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('已拿到部分结果，请继续补全剩余内容。', $messages[1]['content'][0]['text']);
        $this->assertSame('user', $messages[2]['role']);
        $this->assertStringContainsString('Continue exactly from where you left off.', $messages[2]['content']);
    }

    public function test_incomplete_progress_note_is_not_added_to_history_before_retry(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncompletePlainTextProcessor('继续创建前端项目。'),
                $this->makePlainTextProcessor('已完成'),
            );

        $loop = $this->makeLoop($qe);
        $result = $loop->run('继续');

        $this->assertSame('已完成', $result);
        $messages = $loop->getMessageHistory()->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertStringContainsString('Do not narrate progress or announce the next step.', $messages[1]['content']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('已完成', $messages[2]['content'][0]['text']);
    }

    public function test_narration_only_end_turn_is_retried_before_returning(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makePlainTextProcessor('让我尝试用Python来创建文件。'),
                $this->makePlainTextProcessor('已完成'),
            );

        $loop = $this->makeLoop($qe);
        $result = $loop->run('继续');

        $this->assertSame('已完成', $result);
        $messages = $loop->getMessageHistory()->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertStringContainsString('Take the next concrete action immediately.', $messages[1]['content']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('已完成', $messages[2]['content'][0]['text']);
    }

    public function test_narration_only_end_turn_is_retried_instead_of_returned(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makePlainTextProcessor('让我先改用 Bash 来创建文件。'),
                $this->makePlainTextProcessor('已完成'),
            );

        $loop = $this->makeLoop($qe);
        $result = $loop->run('继续');

        $this->assertSame('已完成', $result);
        $messages = $loop->getMessageHistory()->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertStringContainsString('Do not narrate progress or announce the next step.', $messages[1]['content']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('已完成', $messages[2]['content'][0]['text']);
    }

    // ─── abort ────────────────────────────────────────────────────────────

    public function test_abort_sets_is_aborted_flag(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('done'));
        $loop = $this->makeLoop($qe);
        $this->assertFalse($loop->isAborted());
        $loop->abort();
        $this->assertTrue($loop->isAborted());
    }

    public function test_run_resets_aborted_at_start(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $loop->abort();
        // run() should reset aborted and complete normally
        $result = $loop->run('hi');
        $this->assertSame('ok', $result);
    }

    public function test_run_returns_aborted_when_query_is_interrupted_mid_turn(): void
    {
        $processor = $this->createMock(StreamProcessor::class);
        $capturedShouldAbort = null;

        $qe = $this->createMock(QueryEngine::class);
        $loop = null;
        $qe->method('query')->willReturnCallback(function (
            array $systemPrompt,
            array $messages,
            ?callable $onTextDelta = null,
            ?callable $onToolBlockComplete = null,
            ?callable $onThinkingDelta = null,
            ?callable $shouldAbort = null,
        ) use (&$loop, $processor, &$capturedShouldAbort) {
            $capturedShouldAbort = $shouldAbort;
            $loop->abort();

            return $processor;
        });

        $loop = $this->makeLoop($qe);

        $result = $loop->run('please stop');

        $this->assertSame('(aborted)', $result);
        $this->assertNotNull($capturedShouldAbort);
        $this->assertTrue($capturedShouldAbort());
    }

    // ─── isAborted starts false ────────────────────────────────────────────

    public function test_is_aborted_starts_false(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $this->assertFalse($loop->isAborted());
    }

    // ─── token tracking ───────────────────────────────────────────────────

    public function test_input_tokens_accumulated_from_processor(): void
    {
        $processor = new StreamProcessor;
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 42, 'output_tokens' => 7]],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($processor);

        $loop = $this->makeLoop($qe);
        $loop->run('hello');
        $this->assertSame(42, $loop->getTotalInputTokens());
        $this->assertSame(7, $loop->getTotalOutputTokens());
    }

    // ─── onTurnStart callback ─────────────────────────────────────────────

    public function test_on_turn_start_callback_receives_turn_number(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('done'));

        $turns = [];
        $loop = $this->makeLoop($qe);
        $loop->run('go', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });
        $this->assertSame([1], $turns);
    }

    // ─── cost limit stop ──────────────────────────────────────────────────

    public function test_cost_limit_stops_loop_and_returns_message(): void
    {
        // Use a CostTracker with an extremely low stop threshold (0.0 = always stop)
        $costTracker = new \App\Services\Cost\CostTracker(0.0, 0.0);

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnCallback(function () {
            $p = new StreamProcessor;
            $p->processEvent(new \App\Services\Api\StreamEvent('message_start', [
                'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]],
            ]));
            $p->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
                'delta' => ['stop_reason' => 'end_turn'],
            ]));
            return $p;
        });

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);
        $sessionManager = $this->createMock(\App\Services\Session\SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('s');
        $compactor = $this->createMock(\App\Services\Compact\ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturn(false);
        $permissionChecker = $this->createMock(\App\Services\Permissions\PermissionChecker::class);
        $hookExecutor = $this->createMock(\App\Services\Hooks\HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new \App\Services\Hooks\HookResult(true));
        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);

        $loop = new AgentLoop(
            queryEngine: $qe,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: $costTracker,
            toolRegistry: new \App\Tools\ToolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $loop->run('hi');
        $this->assertStringContainsString('Cost limit reached', $result);
    }

    // ─── max turns exceeded ───────────────────────────────────────────────

    public function test_max_turns_exceeded_returns_limit_message(): void
    {
        // Need a tool-use loop that never terminates on its own to hit max turns.
        // We'll simulate this by having every response be a tool_use stop_reason
        // but we have no tool registered → results are errors → loop continues.
        // Instead, mock the stream processor to always return tool_use but never text.
        // The simplest approach: set maxTurns very low via reflection.
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('response'));

        $loop = $this->makeLoop($qe);

        // Override maxTurns to 1 via reflection
        $ref = new \ReflectionProperty(AgentLoop::class, 'maxTurns');
        $ref->setAccessible(true);
        $ref->setValue($loop, 0); // maxTurns=0 → while(0 < 0) is false immediately

        $result = $loop->run('hi');
        $this->assertStringContainsString('maximum turn limit', $result);
    }

    // ─── auto-compact uses last-turn tokens, not cumulative ───────────────

    public function test_auto_compact_does_not_fire_every_turn_after_first_compact(): void
    {
        // Simulate a session where:
        //   Turn 1: 170k input tokens → above threshold → compact fires
        //   Turn 2: 10k input tokens  → below threshold → compact must NOT fire
        //
        // Bug: if shouldAutoCompact() is called with totalInputTokens (cumulative),
        // turn 2 total = 180k → compact fires again on every subsequent turn.
        // Fix: use lastTurnInputTokens so turn 2 checks 10k (below threshold).

        $compactCallCount = 0;

        $compactor = $this->createMock(ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturnCallback(
            function (int $tokens) use (&$compactCallCount): bool {
                // AUTO_COMPACT_THRESHOLD = 167_000
                if ($tokens > 167_000) {
                    $compactCallCount++;
                    return true;
                }
                return false;
            }
        );
        $compactor->method('compact')->willReturn('compacted');

        $turn1Processor = $this->makeProcessorWithTokens(170_000, 'response1');
        $turn2Processor = $this->makeProcessorWithTokens(10_000, 'response2');

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnOnConsecutiveCalls($turn1Processor, $turn2Processor);

        $loop = $this->makeLoop($qe, compactor: $compactor);
        $loop->run('first message');
        $loop->run('second message');

        $this->assertSame(1, $compactCallCount,
            'Auto-compact should fire only once (on turn 1 at 170k tokens), not on turn 2 (which had 10k tokens after compaction). ' .
            'If it fired twice, shouldAutoCompact is using the cumulative totalInputTokens instead of lastTurnInputTokens.');
    }

    // ─── existing tests below ─────────────────────────────────────────────

    public function test_it_retries_the_turn_when_the_model_returns_malformed_tool_input(): void
    {
        $retryMessages = [];
        $queryCount = 0;
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
            ) use (&$queryCount, &$retryMessages) {
                $queryCount++;

                if ($queryCount === 1) {
                    return $this->makeMalformedToolUseProcessor();
                }

                $retryMessages = $messages;

                return $this->makePlainTextProcessor('最终回答');
            });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->never())->method('executeToolBlock');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $costTracker = new CostTracker;

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'Test read tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return new class([
                    'type' => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string'],
                    ],
                ]) extends ToolInputSchema
                {
                    public function validate(array $input): array
                    {
                        if (! isset($input['file_path']) || ! is_string($input['file_path']) || $input['file_path'] === '') {
                            throw new \InvalidArgumentException('Tool input validation failed: The file_path field is required.');
                        }

                        return $input;
                    }
                };
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: $costTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $agent->run('这个代码库是干嘛的');

        $this->assertSame('最终回答', $result);
        $this->assertSame(4, $messageHistory->count());
        $this->assertCount(3, $retryMessages);
        $this->assertSame(['user', 'assistant', 'user'], array_column($retryMessages, 'role'));
        $this->assertSame(
            '{}',
            json_encode($retryMessages[1]['content'][0]['input']),
        );
        $this->assertIsArray($retryMessages[2]['content']);
        $this->assertSame('tool_result', $retryMessages[2]['content'][0]['type']);
        $this->assertTrue($retryMessages[2]['content'][0]['is_error']);
        $this->assertStringContainsString(
            'Tool input validation failed. This tool call was not executed.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'Tool input validation failed: The file_path field is required.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertSame('text', $retryMessages[2]['content'][1]['type']);
        $this->assertStringContainsString(
            'Retry with corrected tool input only. Do not repeat the same malformed call.',
            $retryMessages[2]['content'][1]['text'],
        );
    }

    public function test_it_adds_write_specific_recovery_feedback_before_retrying(): void
    {
        $retryMessages = [];
        $queryCount = 0;

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
            ) use (&$queryCount, &$retryMessages) {
                $queryCount++;

                if ($queryCount === 1) {
                    return $this->makeValidToolUseProcessor('Write', 'toolu_bad_write', []);
                }

                $retryMessages = $messages;

                return $this->makePlainTextProcessor('已恢复');
            });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->never())->method('executeToolBlock');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Write';
            }

            public function description(): string
            {
                return 'Test write tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return new class([
                    'type' => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                ]) extends ToolInputSchema
                {
                    public function validate(array $input): array
                    {
                        if (! isset($input['file_path']) || ! is_string($input['file_path']) || $input['file_path'] === '') {
                            throw new \InvalidArgumentException('Tool input validation failed: The file_path field is required.');
                        }

                        if (! isset($input['content']) || ! is_string($input['content'])) {
                            throw new \InvalidArgumentException('Tool input validation failed: The content field is required.');
                        }

                        return $input;
                    }
                };
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: new CostTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $agent->run('创建 package.json');

        $this->assertSame('已恢复', $result);
        $this->assertSame(4, $messageHistory->count());
        $this->assertCount(3, $retryMessages);
        $this->assertSame(['user', 'assistant', 'user'], array_column($retryMessages, 'role'));
        $this->assertIsArray($retryMessages[2]['content']);
        $this->assertSame('tool_result', $retryMessages[2]['content'][0]['type']);
        $this->assertTrue($retryMessages[2]['content'][0]['is_error']);
        $this->assertStringContainsString(
            'Tool input validation failed. This tool call was not executed.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'For Write: include an absolute file_path',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'do not prefix JSON or file contents with stray ":" placeholder text.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertSame('text', $retryMessages[2]['content'][1]['type']);
        $this->assertStringContainsString(
            'For Write: send a valid JSON object with both absolute file_path and full content strings.',
            $retryMessages[2]['content'][1]['text'],
        );
    }

    public function test_it_reports_tool_input_json_parse_errors_during_retry(): void
    {
        $retryMessages = [];
        $queryCount = 0;

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
            ) use (&$queryCount, &$retryMessages) {
                $queryCount++;

                if ($queryCount === 1) {
                    return $this->makeInvalidJsonToolUseProcessor(
                        'Write',
                        'toolu_bad_json',
                        ':{"file_path":"/tmp/demo.txt"}',
                    );
                }

                $retryMessages = $messages;

                return $this->makePlainTextProcessor('已恢复');
            });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->never())->method('executeToolBlock');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Write';
            }

            public function description(): string
            {
                return 'Test write tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object'], []);
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: new CostTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $agent->run('继续创建文件');

        $this->assertSame('已恢复', $result);
        $this->assertCount(3, $retryMessages);
        $this->assertStringContainsString(
            'Tool input JSON could not be parsed',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'Raw input: :{"file_path":"/tmp/demo.txt"}',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'Split the file into smaller writes or create it in smaller Bash heredoc chunks.',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertStringContainsString(
            'Do not use Agent or Skill as a fallback for ordinary file creation or editing.',
            $retryMessages[2]['content'][1]['text'],
        );
        $this->assertStringContainsString(
            'Prefer a tiny initial Write followed by Edit chunks for long files.',
            $retryMessages[2]['content'][1]['text'],
        );
        $this->assertSame('text', $retryMessages[2]['content'][1]['type']);
        $this->assertStringContainsString(
            'If a large multiline payload keeps breaking tool JSON',
            $retryMessages[2]['content'][1]['text'],
        );
        $this->assertStringContainsString(
            'Do not use Agent or Skill as a fallback',
            $retryMessages[2]['content'][1]['text'],
        );
    }

    public function test_malformed_retry_strips_narration_text_from_assistant_history(): void
    {
        $retryMessages = [];
        $queryCount = 0;

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
            ) use (&$queryCount, &$retryMessages) {
                $queryCount++;

                if ($queryCount === 1) {
                    $processor = new StreamProcessor;
                    $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
                        'message' => ['id' => 'msg_bad_json_with_text', 'usage' => []],
                    ]));
                    $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_start', [
                        'index' => 0,
                        'content_block' => ['type' => 'text', 'text' => ''],
                    ]));
                    $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_delta', [
                        'index' => 0,
                        'delta' => ['type' => 'text_delta', 'text' => '我使用Bash来创建文件，避免JSON编码问题。'],
                    ]));
                    $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_stop', [
                        'index' => 0,
                    ]));
                    $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_start', [
                        'index' => 1,
                        'content_block' => ['type' => 'tool_use', 'id' => 'toolu_bad_bash_text', 'name' => 'Bash'],
                    ]));
                    $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_delta', [
                        'index' => 1,
                        'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"command":"cat <<EOF'],
                    ]));
                    $processor->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
                        'delta' => ['stop_reason' => 'tool_use'],
                    ]));

                    return $processor;
                }

                $retryMessages = $messages;

                return $this->makePlainTextProcessor('已恢复');
            });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->never())->method('executeToolBlock');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Bash';
            }

            public function description(): string
            {
                return 'Test bash tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object'], []);
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: new CostTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $agent->run('继续');

        $this->assertSame('已恢复', $result);
        $this->assertCount(3, $retryMessages);
        $assistantBlocks = $retryMessages[1]['content'];
        $this->assertCount(1, $assistantBlocks);
        $this->assertSame('tool_use', $assistantBlocks[0]['type']);
    }

    public function test_it_only_replays_failed_tool_calls_during_malformed_retry(): void
    {
        $retryMessages = [];
        $queryCount = 0;

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
            ) use (&$queryCount, &$retryMessages) {
                $queryCount++;

                if ($queryCount === 1) {
                    return $this->makeMultiToolUseProcessor([
                        ['id' => 'toolu_read_ok', 'name' => 'Read', 'input' => ['file_path' => '/tmp/example.txt']],
                        ['id' => 'toolu_write_bad', 'name' => 'Write', 'input' => []],
                    ]);
                }

                $retryMessages = $messages;

                return $this->makePlainTextProcessor('已恢复');
            });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->never())->method('executeToolBlock');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'Test read tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return new class([
                    'type' => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string'],
                    ],
                ]) extends ToolInputSchema
                {
                    public function validate(array $input): array
                    {
                        if (! isset($input['file_path']) || ! is_string($input['file_path']) || $input['file_path'] === '') {
                            throw new \InvalidArgumentException('Tool input validation failed: The file_path field is required.');
                        }

                        return $input;
                    }
                };
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Write';
            }

            public function description(): string
            {
                return 'Test write tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return new class([
                    'type' => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                ]) extends ToolInputSchema
                {
                    public function validate(array $input): array
                    {
                        if (! isset($input['file_path']) || ! is_string($input['file_path']) || $input['file_path'] === '') {
                            throw new \InvalidArgumentException('Tool input validation failed: The file_path field is required.');
                        }

                        if (! isset($input['content']) || ! is_string($input['content'])) {
                            throw new \InvalidArgumentException('Tool input validation failed: The content field is required.');
                        }

                        return $input;
                    }
                };
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: new CostTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $agent->run('继续创建文件');

        $this->assertSame('已恢复', $result);
        $this->assertCount(3, $retryMessages);
        $assistantBlocks = array_values(array_filter(
            $retryMessages[1]['content'],
            fn (array $block): bool => ($block['type'] ?? null) === 'tool_use',
        ));
        $this->assertCount(1, $assistantBlocks);
        $this->assertSame('toolu_write_bad', $assistantBlocks[0]['id']);
        $this->assertSame('{}', json_encode($assistantBlocks[0]['input']));
        $this->assertSame('toolu_write_bad', $retryMessages[2]['content'][0]['tool_use_id']);
    }

    public function test_it_retries_the_turn_when_the_model_returns_placeholder_file_references(): void
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $this->makeValidToolUseProcessor('Read', 'toolu_placeholder', ['file_path' => ':0']),
                $this->makePlainTextProcessor('已恢复'),
            );

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->never())->method('executeToolBlock');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'Test read tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return new class([
                    'type' => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string'],
                    ],
                ]) extends ToolInputSchema
                {
                    public function validate(array $input): array
                    {
                        if (! isset($input['file_path']) || ! is_string($input['file_path']) || $input['file_path'] === '') {
                            throw new \InvalidArgumentException('Tool input validation failed: The file_path field is required.');
                        }

                        return $input;
                    }
                };
            }

            public function validateInput(array $input, ToolUseContext $context): ?string
            {
                return ($input['file_path'] ?? null) === ':0'
                    ? 'file_path must include an actual path, not only a line reference like ":12".'
                    : null;
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: new CostTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $agent->run('继续修复');

        $this->assertSame('已恢复', $result);
        $this->assertSame(4, $messageHistory->count());
    }

    public function test_it_retries_the_turn_when_the_model_returns_colon_prefixed_bash_garbage(): void
    {
        $retryMessages = [];

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta = null,
                ?callable $onToolBlockComplete = null,
                ?callable $onThinkingDelta = null,
                ?callable $shouldAbort = null,
            ) use (&$retryMessages) {
                static $queryCount = 0;
                $queryCount++;

                if ($queryCount === 1) {
                    return $this->makeValidToolUseProcessor('Bash', 'toolu_bad_bash', ['command' => ': > /dev/null 2>&1']);
                }

                $retryMessages = $messages;

                return $this->makePlainTextProcessor('已恢复');
            });

        $toolOrchestrator = $this->createMock(ToolOrchestrator::class);
        $toolOrchestrator->expects($this->never())->method('executeToolBlock');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $permissionChecker = $this->createMock(PermissionChecker::class);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'Bash';
            }

            public function description(): string
            {
                return 'Test bash tool';
            }

            public function inputSchema(): ToolInputSchema
            {
                return new class([
                    'type' => 'object',
                    'properties' => [
                        'command' => ['type' => 'string'],
                    ],
                ]) extends ToolInputSchema
                {
                    public function validate(array $input): array
                    {
                        if (! isset($input['command']) || ! is_string($input['command']) || $input['command'] === '') {
                            throw new \InvalidArgumentException('Tool input validation failed: The command field is required.');
                        }

                        return $input;
                    }
                };
            }

            public function validateInput(array $input, ToolUseContext $context): ?string
            {
                return str_starts_with(ltrim((string) ($input['command'] ?? '')), ':')
                    ? 'command must not start with ":"; that is a shell no-op or malformed placeholder prefix. Run the real command directly.'
                    : null;
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: new CostTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $result = $agent->run('继续执行真实命令');

        $this->assertSame('已恢复', $result);
        $this->assertSame(4, $messageHistory->count());
        $this->assertCount(3, $retryMessages);
        $this->assertSame('tool_result', $retryMessages[2]['content'][0]['type']);
        $this->assertStringContainsString(
            'do not send shell no-ops or probes such as ": > /dev/null 2>&1" or "true".',
            $retryMessages[2]['content'][0]['content'],
        );
        $this->assertSame('text', $retryMessages[2]['content'][1]['type']);
        $this->assertStringContainsString(
            'Never send ":" placeholders or no-op probes like ": > /dev/null 2>&1" or "true"',
            $retryMessages[2]['content'][1]['text'],
        );
        $this->assertStringContainsString(
            'Keep Bash commands short and concrete; avoid giant multiline file-generation commands.',
            $retryMessages[2]['content'][1]['text'],
        );
    }

    public function test_it_cleans_up_streaming_tools_when_querying_throws_after_tool_start(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork is required for this test.');
        }

        $queryEngine = $this->createMock(QueryEngine::class);
        $queryEngine->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (
                array $systemPrompt,
                array $messages,
                ?callable $onTextDelta,
                ?callable $onToolBlockComplete,
            ): never {
                if ($onToolBlockComplete !== null) {
                    $onToolBlockComplete([
                        'id' => 'toolu_cleanup',
                        'name' => 'SafeSleepTool',
                        'input' => [],
                    ], 0);
                }

                throw new \RuntimeException('stream failed after tool start');
            });

        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new class extends BaseTool
        {
            public function name(): string
            {
                return 'SafeSleepTool';
            }

            public function description(): string
            {
                return 'A safe tool that sleeps briefly.';
            }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => [],
                ]);
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                usleep(500000);

                return ToolResult::success('done');
            }
        });

        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->method('check')->willReturn(\App\Services\Permissions\PermissionDecision::allow());

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $toolOrchestrator = new ToolOrchestrator(
            toolRegistry: $toolRegistry,
            permissionChecker: $permissionChecker,
            hookExecutor: $hookExecutor,
        );

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $messageHistory = new MessageHistory;

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');
        $sessionManager->method('recordEntry');
        $sessionManager->method('recordTurn');

        $contextCompactor = $this->createMock(ContextCompactor::class);
        $contextCompactor->method('shouldAutoCompact')->willReturn(false);

        $agent = new AgentLoop(
            queryEngine: $queryEngine,
            toolOrchestrator: $toolOrchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $messageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $contextCompactor,
            costTracker: new CostTracker,
            toolRegistry: $toolRegistry,
            hookExecutor: $hookExecutor,
        );

        $tempFile = sys_get_temp_dir() . '/haocode_stream_0_' . getmypid() . '_toolu_cleanup';

        try {
            $agent->run('请探索这个仓库');
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('stream failed after tool start', $e->getMessage());
            $this->assertFileDoesNotExist($tempFile);
        }
    }

    // ─── no consecutive user messages after a tool-use turn ───────────────

    public function test_tool_use_turn_does_not_produce_consecutive_user_messages(): void
    {
        // Turn 1: model calls an unsafe (queued) tool
        $toolUseProcessor = $this->makeValidToolUseProcessor('Echo', 'toolu_echo_1', []);
        // Turn 2: model responds with end_turn text
        $endTurnProcessor = $this->makePlainTextProcessor('done');

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnOnConsecutiveCalls($toolUseProcessor, $endTurnProcessor);

        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Echo', fn() => ToolResult::success('echoed')));

        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->method('check')->willReturn(\App\Services\Permissions\PermissionDecision::allow());

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $orchestrator = new \App\Services\Agent\ToolOrchestrator($registry, $permissionChecker, $hookExecutor);

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');

        $compactor = $this->createMock(\App\Services\Compact\ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturn(false);

        $history = new MessageHistory;

        $loop = new AgentLoop(
            queryEngine: $qe,
            toolOrchestrator: $orchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: $history,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: new \App\Services\Cost\CostTracker,
            toolRegistry: $registry,
            hookExecutor: $hookExecutor,
        );

        $loop->run('call Echo tool please');

        $messages = $history->getMessagesForApi();

        // Ensure no two consecutive messages have the same role
        for ($i = 1; $i < count($messages); $i++) {
            $this->assertNotSame(
                $messages[$i - 1]['role'],
                $messages[$i]['role'],
                "Consecutive messages at positions " . ($i - 1) . " and {$i} have role '{$messages[$i]['role']}'"
            );
        }
    }

    private function makeValidToolUseProcessor(string $toolName, string $toolId, array $input): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_tool', 'usage' => []],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => $toolId,
                'name' => $toolName,
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => json_encode($input),
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_stop', [
            'index' => 0,
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'tool_use'],
        ]));

        return $processor;
    }

    /**
     * @param array<int, array{id: string, name: string, input: array}> $blocks
     */
    private function makeMultiToolUseProcessor(array $blocks): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_multi_tool', 'usage' => []],
        ]));

        foreach ($blocks as $index => $block) {
            $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_start', [
                'index' => $index,
                'content_block' => [
                    'type' => 'tool_use',
                    'id' => $block['id'],
                    'name' => $block['name'],
                ],
            ]));
            $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_delta', [
                'index' => $index,
                'delta' => [
                    'type' => 'input_json_delta',
                    'partial_json' => json_encode($block['input']),
                ],
            ]));
            $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_stop', [
                'index' => $index,
            ]));
        }

        $processor->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'tool_use'],
        ]));

        return $processor;
    }

    private function makeMalformedToolUseProcessor(): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_1', 'usage' => []],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_bad',
                'name' => 'Read',
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => '[]',
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'tool_use'],
        ]));

        return $processor;
    }

    private function makeInvalidJsonToolUseProcessor(string $toolName, string $toolId, string $rawInput): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_bad_json', 'usage' => []],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => $toolId,
                'name' => $toolName,
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => $rawInput,
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'tool_use'],
        ]));

        return $processor;
    }

    private function makeProcessorWithTokens(int $inputTokens, string $text): StreamProcessor
    {
        $processor = new StreamProcessor;
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_x', 'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => 1]],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_stop', ['index' => 0]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));
        return $processor;
    }

    // ─── token getters initial values ─────────────────────────────────────

    public function test_total_input_tokens_starts_at_zero(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $this->assertSame(0, $loop->getTotalInputTokens());
    }

    public function test_total_output_tokens_starts_at_zero(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $this->assertSame(0, $loop->getTotalOutputTokens());
    }

    // ─── getMessageHistory ────────────────────────────────────────────────

    public function test_get_message_history_returns_history_instance(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $history = $loop->getMessageHistory();
        $this->assertInstanceOf(MessageHistory::class, $history);
    }

    public function test_run_adds_user_and_assistant_messages_to_history(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('response text'));
        $loop = $this->makeLoop($qe);
        $loop->run('test user input');

        $history = $loop->getMessageHistory();
        $messages = $history->getMessagesForApi();
        $this->assertGreaterThanOrEqual(2, count($messages));

        $roles = array_column($messages, 'role');
        $this->assertContains('user', $roles);
        $this->assertContains('assistant', $roles);
    }

    // ─── getEstimatedCost ─────────────────────────────────────────────────

    public function test_get_estimated_cost_starts_at_zero(): void
    {
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('ok'));
        $loop = $this->makeLoop($qe);
        $this->assertSame(0.0, $loop->getEstimatedCost());
    }

    // ─── getCacheTokens ───────────────────────────────────────────────────

    public function test_cache_tokens_tracked_from_processor_usage(): void
    {
        $processor = new StreamProcessor;
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
            'message' => [
                'id' => 'msg_1',
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 10,
                    'cache_creation_input_tokens' => 50,
                    'cache_read_input_tokens' => 25,
                ],
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($processor);

        $loop = $this->makeLoop($qe);
        $loop->run('hello');
        $this->assertSame(50, $loop->getCacheCreationTokens());
        $this->assertSame(25, $loop->getCacheReadTokens());
    }

    // ─── onTurnStart increments turn number ───────────────────────────────

    public function test_on_turn_start_receives_turn_number_one_for_single_turn(): void
    {
        // onTurnStart receives the turn number within a single run() call.
        // For a simple end_turn response (no tool use), there is exactly 1 turn.
        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturn($this->makePlainTextProcessor('done'));

        $turns = [];
        $loop = $this->makeLoop($qe);
        $loop->run('first', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });
        $loop->run('second', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });
        $loop->run('third', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });

        // Each run() resets turnCount to 0, so each call starts at turn 1
        $this->assertSame([1, 1, 1], $turns);
    }

    public function test_on_turn_start_receives_incrementing_turn_numbers_within_multi_turn_run(): void
    {
        // When tool_use responses cause multiple turns within one run(),
        // onTurnStart should receive incrementing numbers.
        $turn1Processor = $this->makeValidToolUseProcessor('Echo', 'toolu_1', []);
        $turn2Processor = $this->makePlainTextProcessor('final answer');

        $qe = $this->createMock(QueryEngine::class);
        $qe->method('query')->willReturnOnConsecutiveCalls($turn1Processor, $turn2Processor);

        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Echo', fn() => ToolResult::success('echoed')));

        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->method('check')->willReturn(\App\Services\Permissions\PermissionDecision::allow());

        $hookExecutor = $this->createMock(HookExecutor::class);
        $hookExecutor->method('execute')->willReturn(new HookResult(true));

        $orchestrator = new \App\Services\Agent\ToolOrchestrator($registry, $permissionChecker, $hookExecutor);

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildSystemPrompt')->willReturn([]);

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSessionId')->willReturn('test-session');

        $compactor = $this->createMock(\App\Services\Compact\ContextCompactor::class);
        $compactor->method('shouldAutoCompact')->willReturn(false);

        $loop = new AgentLoop(
            queryEngine: $qe,
            toolOrchestrator: $orchestrator,
            contextBuilder: $contextBuilder,
            messageHistory: new MessageHistory,
            permissionChecker: $permissionChecker,
            sessionManager: $sessionManager,
            contextCompactor: $compactor,
            costTracker: new \App\Services\Cost\CostTracker,
            toolRegistry: $registry,
            hookExecutor: $hookExecutor,
        );

        $turns = [];
        $loop->run('call Echo then answer', onTurnStart: function (int $n) use (&$turns) { $turns[] = $n; });

        $this->assertSame([1, 2], $turns);
    }

    private function makePlainTextProcessor(string $text): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_2', 'usage' => []],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'text',
                'text' => '',
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'text_delta',
                'text' => $text,
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_stop', [
            'index' => 0,
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
        ]));

        return $processor;
    }

    private function makeIncompletePlainTextProcessor(string $text): StreamProcessor
    {
        $processor = new StreamProcessor;

        $processor->processEvent(new \App\Services\Api\StreamEvent('message_start', [
            'message' => ['id' => 'msg_incomplete', 'usage' => []],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_start', [
            'index' => 0,
            'content_block' => [
                'type' => 'text',
                'text' => '',
            ],
        ]));
        $processor->processEvent(new \App\Services\Api\StreamEvent('content_block_delta', [
            'index' => 0,
            'delta' => [
                'type' => 'text_delta',
                'text' => $text,
            ],
        ]));

        return $processor;
    }
}
