<?php

namespace Tests\Unit;

use App\Tools\Skill\SkillDefinition;
use App\Tools\Skill\SkillLoader;
use App\Tools\Skill\SkillTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class SkillToolTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'sess_test_123',
        );
    }

    private function makeLoader(array $skills = []): SkillLoader
    {
        $loader = $this->createMock(SkillLoader::class);
        $loader->method('loadSkills')->willReturn($skills);
        $loader->method('findSkill')->willReturnCallback(function (string $name) use ($skills) {
            $name = ltrim($name, '/');
            return $skills[$name] ?? null;
        });
        return $loader;
    }

    private function makeSkill(string $name, string $prompt, array $overrides = []): SkillDefinition
    {
        return new SkillDefinition(
            name: $name,
            description: $overrides['description'] ?? 'A skill',
            whenToUse: null,
            prompt: $prompt,
            allowedTools: $overrides['allowedTools'] ?? [],
            model: $overrides['model'] ?? null,
            context: 'inline',
            userInvocable: true,
            argumentHint: $overrides['argumentHint'] ?? null,
            skillDir: '/skills/dir',
        );
    }

    private function callTool(SkillTool $tool, array $input): \App\Tools\ToolResult
    {
        return $tool->call($input, $this->context);
    }

    // ─── expandPrompt — $ARGUMENTS substitution ───────────────────────────

    public function test_arguments_substitution_in_prompt(): void
    {
        $skill = $this->makeSkill('deploy', 'Deploy to $ARGUMENTS environment');
        $loader = $this->makeLoader(['deploy' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'deploy', 'args' => 'production'], $this->context);

        $this->assertStringContainsString('Deploy to production environment', $result->output);
    }

    public function test_arguments_empty_string_when_not_provided(): void
    {
        $skill = $this->makeSkill('test', 'Run tests with $ARGUMENTS flags');
        $loader = $this->makeLoader(['test' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'test'], $this->context);

        $this->assertStringContainsString('Run tests with  flags', $result->output);
    }

    // ─── expandPrompt — session variable substitution ─────────────────────

    public function test_session_id_substitution(): void
    {
        $skill = $this->makeSkill('log', 'Session: ${CLAUDE_SESSION_ID}');
        $loader = $this->makeLoader(['log' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'log'], $this->context);

        $this->assertStringContainsString('Session: sess_test_123', $result->output);
    }

    public function test_haocode_session_id_substitution(): void
    {
        $skill = $this->makeSkill('log', 'ID: ${HAOCODE_SESSION_ID}');
        $loader = $this->makeLoader(['log' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'log'], $this->context);

        $this->assertStringContainsString('ID: sess_test_123', $result->output);
    }

    public function test_skill_dir_substitution(): void
    {
        $skill = $this->makeSkill('info', 'Dir: ${CLAUDE_SKILL_DIR}');
        $loader = $this->makeLoader(['info' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'info'], $this->context);

        $this->assertStringContainsString('Dir: /skills/dir', $result->output);
    }

    // ─── expandPrompt — shell command substitution ────────────────────────

    public function test_inline_shell_command_executed(): void
    {
        $skill = $this->makeSkill('echo', 'Result: !`echo hello`');
        $loader = $this->makeLoader(['echo' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'echo'], $this->context);

        $this->assertStringContainsString('Result: hello', $result->output);
    }

    // ─── leading slash stripped ───────────────────────────────────────────

    public function test_leading_slash_in_skill_name_stripped(): void
    {
        $skill = $this->makeSkill('commit', 'Commit the changes');
        $loader = $this->makeLoader(['commit' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => '/commit'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Commit the changes', $result->output);
    }

    // ─── unknown skill falls back to list ─────────────────────────────────

    public function test_unknown_skill_shows_list_with_error_prefix(): void
    {
        $skill = $this->makeSkill('commit', 'Commit');
        $loader = $this->makeLoader(['commit' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'no-such-skill'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Unknown skill', $result->output);
        $this->assertStringContainsString('/commit', $result->output);
    }

    // ─── list action ──────────────────────────────────────────────────────

    public function test_list_action_shows_skills(): void
    {
        $skill = $this->makeSkill('review', 'Review code', ['argumentHint' => 'pr-number']);
        $loader = $this->makeLoader(['review' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'list', 'action' => 'list'], $this->context);

        $this->assertStringContainsString('/review', $result->output);
        $this->assertStringContainsString('pr-number', $result->output);
    }

    public function test_list_action_with_no_skills_shows_help_message(): void
    {
        $loader = $this->makeLoader([]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'list'], $this->context);

        $this->assertStringContainsString('No skills available', $result->output);
    }

    // ─── result metadata ──────────────────────────────────────────────────

    public function test_successful_run_includes_skill_name_in_metadata(): void
    {
        $skill = $this->makeSkill('build', 'Build the project', ['allowedTools' => ['Bash']]);
        $loader = $this->makeLoader(['build' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'build'], $this->context);

        $this->assertSame('build', $result->metadata['skill']);
        $this->assertSame(['Bash'], $result->metadata['allowed_tools']);
    }

    // ─── isReadOnly ───────────────────────────────────────────────────────

    public function test_is_not_read_only(): void
    {
        $this->assertFalse((new SkillTool)->isReadOnly([]));
    }
}
