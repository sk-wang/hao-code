<?php
namespace Tests\Unit;

use App\Services\Hooks\HookDefinition;
use PHPUnit\Framework\TestCase;

class HookDefinitionTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        $hook = new HookDefinition(
            event: 'SessionStart',
            command: 'echo hello',
            matcher: 'Bash(git*)',
        );

        $this->assertSame('SessionStart', $hook->event);
        $this->assertSame('echo hello', $hook->command);
        $this->assertSame('Bash(git*)', $hook->matcher);
    }

    public function test_matcher_defaults_to_null(): void
    {
        $hook = new HookDefinition(
            event: 'Stop',
            command: 'echo done',
        );

        $this->assertNull($hook->matcher);
    }
}
