<?php

namespace App\Tools\Skill;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class SkillTool extends BaseTool
{
    public function name(): string
    {
        return 'Skill';
    }

    public function description(): string
    {
        return <<<DESC
Execute a skill (slash command). Skills are predefined prompts that can be invoked by name.

Usage: Call with the skill name (with or without leading /) and optional arguments.
When executed, the skill's prompt is expanded and injected into the conversation.

To list available skills, call with no arguments or use list action.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'skill' => [
                    'type' => 'string',
                    'description' => 'The skill name to invoke (e.g., "commit" or "/commit")',
                ],
                'args' => [
                    'type' => 'string',
                    'description' => 'Optional arguments to pass to the skill',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['run', 'list'],
                    'description' => 'Action: "run" to execute, "list" to show available skills',
                ],
            ],
            'required' => ['skill'],
        ], [
            'skill' => 'required|string',
            'args' => 'nullable|string',
            'action' => 'nullable|string|in:run,list',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        /** @var SkillLoader $loader */
        $loader = app(SkillLoader::class);
        $skillName = ltrim($input['skill'], '/');
        $args = $input['args'] ?? '';
        $action = $input['action'] ?? 'run';

        if ($action === 'list' || $skillName === 'list') {
            return $this->listSkills($loader);
        }

        $skill = $loader->findSkill($skillName);
        if ($skill === null) {
            return $this->listSkills($loader, "Unknown skill: /{$skillName}");
        }

        // Expand the skill prompt with argument substitution
        $prompt = $this->expandPrompt($skill, $args, $context);

        return ToolResult::success($prompt, [
            'skill' => $skillName,
            'allowed_tools' => $skill->allowedTools,
            'model_override' => $skill->model,
        ]);
    }

    private function listSkills(SkillLoader $loader, string $prefix = ''): ToolResult
    {
        $skills = $loader->loadSkills();
        if (empty($skills)) {
            $msg = $prefix ? $prefix . "\n\n" : '';
            $msg .= "No skills available. Add skills to ~/.haocode/skills/ or .haocode/skills/";
            return ToolResult::success($msg);
        }

        $lines = [];
        if ($prefix) $lines[] = $prefix;
        $lines[] = "Available skills:";
        foreach ($skills as $name => $skill) {
            $hint = $skill->argumentHint ? " <{$skill->argumentHint}>" : '';
            $lines[] = "  /{$name}{$hint} — {$skill->description}";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function expandPrompt(SkillDefinition $skill, string $args, ToolUseContext $context): string
    {
        $prompt = $skill->prompt;

        // Substitute $ARGUMENTS
        $prompt = str_replace('$ARGUMENTS', $args, $prompt);

        // Substitute session variables
        $prompt = str_replace('${CLAUDE_SESSION_ID}', $context->sessionId, $prompt);
        $prompt = str_replace('${HAOCODE_SESSION_ID}', $context->sessionId, $prompt);

        // Substitute skill directory
        $prompt = str_replace('${CLAUDE_SKILL_DIR}', $skill->skillDir, $prompt);
        $prompt = str_replace('${HAOCODE_SKILL_DIR}', $skill->skillDir, $prompt);

        // Execute inline shell commands: !`command`
        $prompt = preg_replace_callback('/!\`([^\`]+)\`/', function ($m) {
            $output = shell_exec($m[1]);
            return trim($output ?? '');
        }, $prompt);

        // Execute shell blocks: ```! ... ```
        $prompt = preg_replace_callback('/```!\s*\n(.*?)```/s', function ($m) {
            $output = shell_exec($m[1]);
            return trim($output ?? '');
        }, $prompt);

        return $prompt;
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return true;
    }
}
