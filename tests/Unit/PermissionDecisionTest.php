<?php

namespace Tests\Unit;

use App\Services\Permissions\PermissionDecision;
use PHPUnit\Framework\TestCase;

class PermissionDecisionTest extends TestCase
{
    public function test_allow_is_allowed_and_does_not_need_prompt(): void
    {
        $decision = PermissionDecision::allow();

        $this->assertTrue($decision->allowed);
        $this->assertFalse($decision->needsPrompt);
        $this->assertNull($decision->reason);
    }

    public function test_deny_is_not_allowed_and_does_not_need_prompt(): void
    {
        $decision = PermissionDecision::deny('dangerous command');

        $this->assertFalse($decision->allowed);
        $this->assertFalse($decision->needsPrompt);
        $this->assertSame('dangerous command', $decision->reason);
    }

    public function test_deny_with_no_reason_defaults_to_empty_string(): void
    {
        $decision = PermissionDecision::deny();

        $this->assertFalse($decision->allowed);
        $this->assertSame('', $decision->reason);
    }

    public function test_ask_is_not_allowed_and_needs_prompt(): void
    {
        $decision = PermissionDecision::ask('needs confirmation');

        $this->assertFalse($decision->allowed);
        $this->assertTrue($decision->needsPrompt);
        $this->assertSame('needs confirmation', $decision->reason);
    }

    public function test_ask_with_no_reason_uses_default_message(): void
    {
        $decision = PermissionDecision::ask();

        $this->assertTrue($decision->needsPrompt);
        $this->assertStringContainsString('approval', $decision->reason);
    }
}
