<?php

namespace Tests\Unit;

use App\Tools\AskUserQuestion\AskUserQuestionTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class AskUserQuestionToolTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );
    }

    // ─── name / description / isReadOnly ─────────────────────────────────

    public function test_name_is_ask_user_question(): void
    {
        $this->assertSame('AskUserQuestion', (new AskUserQuestionTool)->name());
    }

    public function test_description_is_not_empty(): void
    {
        $this->assertNotEmpty((new AskUserQuestionTool)->description());
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue((new AskUserQuestionTool)->isReadOnly([]));
    }

    // ─── call — auto-selects first option ─────────────────────────────────

    public function test_single_question_returns_first_option(): void
    {
        $tool = new AskUserQuestionTool;
        $result = $tool->call([
            'questions' => [
                [
                    'question' => 'Which library?',
                    'header'   => 'Library',
                    'options'  => [
                        ['label' => 'React', 'description' => 'Facebook library'],
                        ['label' => 'Vue', 'description' => 'Progressive framework'],
                    ],
                ],
            ],
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('React', $result->output);
        $this->assertStringContainsString('Which library?', $result->output);
    }

    public function test_multiple_questions_each_get_first_option(): void
    {
        $tool = new AskUserQuestionTool;
        $result = $tool->call([
            'questions' => [
                [
                    'question' => 'Auth method?',
                    'header'   => 'Auth',
                    'options'  => [
                        ['label' => 'JWT'],
                        ['label' => 'Session'],
                    ],
                ],
                [
                    'question' => 'Database?',
                    'header'   => 'DB',
                    'options'  => [
                        ['label' => 'MySQL'],
                        ['label' => 'PostgreSQL'],
                    ],
                ],
            ],
        ], $this->context);

        $this->assertStringContainsString('JWT', $result->output);
        $this->assertStringContainsString('MySQL', $result->output);
    }

    public function test_output_mentions_auto_selected(): void
    {
        $tool = new AskUserQuestionTool;
        $result = $tool->call([
            'questions' => [
                [
                    'question' => 'Pick one?',
                    'header'   => 'Pick',
                    'options'  => [['label' => 'Option A'], ['label' => 'Option B']],
                ],
            ],
        ], $this->context);

        $this->assertStringContainsString('auto', strtolower($result->output));
    }

    public function test_question_with_empty_options_returns_na(): void
    {
        $tool = new AskUserQuestionTool;
        $result = $tool->call([
            'questions' => [
                [
                    'question' => 'Empty?',
                    'header'   => 'Empty',
                    'options'  => [],
                ],
            ],
        ], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('N/A', $result->output);
    }
}
