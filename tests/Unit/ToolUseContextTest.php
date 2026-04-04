<?php
namespace Tests\Unit;

use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class ToolUseContextTest extends TestCase
{
    public function test_constructor_sets_properties(): void
    {
        $context = new ToolUseContext(
            workingDirectory: '/tmp',
            sessionId: 'abc123',
        );

        $this->assertSame('/tmp', $context->workingDirectory);
        $this->assertSame('abc123', $context->sessionId);
    }

    public function test_is_aborted_returns_false_by_default(): void
    {
        $context = new ToolUseContext('/tmp', 'abc');
        $this->assertFalse($context->isAborted());
    }

    public function test_is_aborted_returns_true_when_should_abort_returns_true(): void
    {
        $context = new ToolUseContext('/tmp', 'abc', shouldAbort: fn() => true);
        $this->assertTrue($context->isAborted());
    }

    public function test_is_aborted_returns_false_when_should_abort_returns_false(): void
    {
        $context = new ToolUseContext('/tmp', 'abc', shouldAbort: fn() => false);
        $this->assertFalse($context->isAborted());
    }

    public function test_on_progress_callback_is_stored(): void
    {
        $called = false;
        $context = new ToolUseContext('/tmp', 'abc', onProgress: function ($value) use (&$called) {
            $called = true;
        });

        $this->assertInstanceOf(\Closure::class, $context->onProgress);
        ($context->onProgress)('test');
        $this->assertTrue($called);
    }
}
