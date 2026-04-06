<?php

namespace Tests\Unit;

use App\Tools\Agent\BuiltInAgents;
use PHPUnit\Framework\TestCase;

class BuiltInAgentsTest extends TestCase
{
    public function test_all_returns_agents(): void
    {
        $agents = BuiltInAgents::all();
        $this->assertNotEmpty($agents);
    }

    public function test_has_general_purpose(): void
    {
        $agent = BuiltInAgents::get('general-purpose');
        $this->assertNotNull($agent);
        $this->assertSame('general-purpose', $agent->agentType);
        $this->assertContains('*', $agent->tools);
    }

    public function test_has_explore(): void
    {
        $agent = BuiltInAgents::get('Explore');
        $this->assertNotNull($agent);
        $this->assertTrue($agent->readOnly);
        $this->assertSame('haiku', $agent->model);
        $this->assertContains('Edit', $agent->disallowedTools);
        $this->assertContains('Write', $agent->disallowedTools);
    }

    public function test_has_plan(): void
    {
        $agent = BuiltInAgents::get('Plan');
        $this->assertNotNull($agent);
        $this->assertTrue($agent->readOnly);
        $this->assertContains('Agent', $agent->disallowedTools);
    }

    public function test_has_code_reviewer(): void
    {
        $agent = BuiltInAgents::get('code-reviewer');
        $this->assertNotNull($agent);
        $this->assertContains('*', $agent->tools);
    }

    public function test_has_bug_analyzer(): void
    {
        $agent = BuiltInAgents::get('bug-analyzer');
        $this->assertNotNull($agent);
        $this->assertTrue($agent->readOnly);
    }

    public function test_has_verification(): void
    {
        $agent = BuiltInAgents::get('verification');
        $this->assertNotNull($agent);
        $this->assertTrue($agent->background);
        $this->assertTrue($agent->readOnly);
    }

    public function test_explore_tool_restriction(): void
    {
        $agent = BuiltInAgents::get('Explore');

        $this->assertTrue($agent->isToolAllowed('Read'));
        $this->assertTrue($agent->isToolAllowed('Grep'));
        $this->assertTrue($agent->isToolAllowed('Glob'));
        $this->assertFalse($agent->isToolAllowed('Edit'));
        $this->assertFalse($agent->isToolAllowed('Write'));
        $this->assertFalse($agent->isToolAllowed('Agent'));
    }

    public function test_general_purpose_allows_all(): void
    {
        $agent = BuiltInAgents::get('general-purpose');

        $this->assertTrue($agent->isToolAllowed('Read'));
        $this->assertTrue($agent->isToolAllowed('Edit'));
        $this->assertTrue($agent->isToolAllowed('Bash'));
        $this->assertTrue($agent->isToolAllowed('Agent'));
    }

    public function test_description_block(): void
    {
        $block = BuiltInAgents::descriptionBlock();
        $this->assertStringContainsString('general-purpose', $block);
        $this->assertStringContainsString('Explore', $block);
        $this->assertStringContainsString('Plan', $block);
    }

    public function test_unknown_agent_returns_null(): void
    {
        $this->assertNull(BuiltInAgents::get('nonexistent'));
    }
}
