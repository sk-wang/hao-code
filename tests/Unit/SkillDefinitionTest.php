<?php
namespace Tests\Unit;

use App\Tools\Skill\SkillDefinition;
use PHPUnit\Framework\TestCase;

class SkillDefinitionTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        $skill = new SkillDefinition(
            name: 'test-skill',
            description: 'A test skill',
            whenToUse: 'Use for testing',
            prompt: 'Do a test.',
            allowedTools: ['Read', 'Write'],
            model: 'claude-sonnet',
            context: 'fork',
            userInvocable: false,
            argumentHint: 'test-arg',
            skillDir: '/tmp/skills/test',
        );

        $this->assertSame('test-skill', $skill->name);
        $this->assertSame('A test skill', $skill->description);
        $this->assertSame('Use for testing', $skill->whenToUse);
        $this->assertSame('Do a test.', $skill->prompt);
        $this->assertSame(['Read', 'Write'], $skill->allowedTools);
        $this->assertSame('claude-sonnet', $skill->model);
        $this->assertSame('fork', $skill->context);
        $this->assertFalse($skill->userInvocable);
        $this->assertSame('test-arg', $skill->argumentHint);
        $this->assertSame('/tmp/skills/test', $skill->skillDir);
    }

    public function test_defaults_are_applied(): void
    {
        $skill = new SkillDefinition(
            name: 'minimal',
            description: 'Minimal skill',
            whenToUse: null,
            prompt: 'Go.',
        );

        $this->assertSame([], $skill->allowedTools);
        $this->assertNull($skill->model);
        $this->assertSame('inline', $skill->context);
        $this->assertTrue($skill->userInvocable);
        $this->assertNull($skill->argumentHint);
        $this->assertSame('', $skill->skillDir);
    }
}
