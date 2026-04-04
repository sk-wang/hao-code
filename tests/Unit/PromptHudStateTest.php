<?php

namespace Tests\Unit;

use App\Support\Terminal\PromptHudState;
use PHPUnit\Framework\TestCase;

class PromptHudStateTest extends TestCase
{
    public function test_hydrate_from_session_entries_tracks_recent_tools_and_todos(): void
    {
        $state = new PromptHudState;

        $state->hydrateFromSessionEntries([
            [
                'type' => 'assistant_turn',
                'message' => [
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => 'read_1',
                            'name' => 'Read',
                            'input' => ['file_path' => '/tmp/src/AuthService.php'],
                        ],
                        [
                            'type' => 'tool_use',
                            'id' => 'grep_1',
                            'name' => 'Grep',
                            'input' => ['pattern' => 'Auth::attempt'],
                        ],
                        [
                            'type' => 'tool_use',
                            'id' => 'todo_1',
                            'name' => 'TodoWrite',
                            'input' => [
                                'todos' => [
                                    ['content' => 'Inspect auth flow', 'status' => 'completed', 'activeForm' => 'Inspecting auth flow'],
                                    ['content' => 'Add regression tests', 'status' => 'in_progress', 'activeForm' => 'Adding regression tests'],
                                ],
                            ],
                        ],
                    ],
                ],
                'tool_results' => [
                    ['tool_use_id' => 'read_1', 'content' => 'ok', 'is_error' => false],
                    ['tool_use_id' => 'grep_1', 'content' => 'no matches', 'is_error' => true],
                    ['tool_use_id' => 'todo_1', 'content' => 'updated', 'is_error' => false],
                ],
            ],
        ]);

        $tools = $state->summarizeTools();
        $todo = $state->summarizeTodos();

        $this->assertSame([], $tools['running']);
        $this->assertSame([
            ['name' => 'Read', 'count' => 1],
            ['name' => 'Grep', 'count' => 1],
        ], $tools['completed']);
        $this->assertSame([
            'current' => 'Add regression tests',
            'completed' => 1,
            'total' => 2,
            'all_completed' => false,
        ], $todo);
    }

    public function test_hydrate_tracks_task_create_update_and_stop_flow(): void
    {
        $state = new PromptHudState;

        $state->hydrateFromSessionEntries([
            [
                'type' => 'assistant_turn',
                'message' => [
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => 'create_1',
                            'name' => 'TaskCreate',
                            'input' => [
                                'subject' => 'Trace failing spec',
                                'activeForm' => 'Tracing failing spec',
                            ],
                        ],
                        [
                            'type' => 'tool_use',
                            'id' => 'create_2',
                            'name' => 'TaskCreate',
                            'input' => [
                                'subject' => 'Patch auth handler',
                                'activeForm' => 'Patching auth handler',
                                'status' => 'in_progress',
                            ],
                        ],
                        [
                            'type' => 'tool_use',
                            'id' => 'update_1',
                            'name' => 'TaskUpdate',
                            'input' => [
                                'id' => 'task_alpha',
                                'status' => 'completed',
                            ],
                        ],
                        [
                            'type' => 'tool_use',
                            'id' => 'stop_1',
                            'name' => 'TaskStop',
                            'input' => [
                                'id' => 'task_beta',
                            ],
                        ],
                    ],
                ],
                'tool_results' => [
                    ['tool_use_id' => 'create_1', 'content' => "Created task: task_alpha\nSubject: Trace failing spec", 'is_error' => false],
                    ['tool_use_id' => 'create_2', 'content' => "Created task: task_beta\nSubject: Patch auth handler", 'is_error' => false],
                    ['tool_use_id' => 'update_1', 'content' => 'updated', 'is_error' => false],
                    ['tool_use_id' => 'stop_1', 'content' => 'stopped', 'is_error' => false],
                ],
            ],
        ]);

        $this->assertSame([
            'current' => null,
            'completed' => 2,
            'total' => 2,
            'all_completed' => true,
        ], $state->summarizeTodos());
    }

    public function test_record_turn_event_keeps_latest_internal_status_snapshot(): void
    {
        $state = new PromptHudState;

        $state->recordTurnEvent('turn.started', 'Investigate provider config');
        $state->recordTurnEvent('tool.completed', 'Read · config/haocode.php');

        $this->assertSame([
            'event' => 'tool.completed',
            'label' => 'Tool finished',
            'detail' => 'Read · config/haocode.php',
        ], $state->summarizeTurn());
    }
}
