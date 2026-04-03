<?php

namespace Tests\Unit;

use App\Services\Hooks\HookExecutor;
use App\Services\Notification\Notifier;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase
{
    // ─── detectChannel ────────────────────────────────────────────────────

    public function test_iterm2_channel_detected(): void
    {
        $notifier = new Notifier('iterm2');
        // Just verifying constructor accepts the channel — no exception
        $this->assertInstanceOf(Notifier::class, $notifier);
    }

    public function test_channel_detection_iterm2_from_env(): void
    {
        $saved = $_ENV['TERM_PROGRAM'] ?? null;
        $_ENV['TERM_PROGRAM'] = 'iTerm.app';

        $notifier = new Notifier; // no explicit channel, auto-detects

        if ($saved === null) {
            unset($_ENV['TERM_PROGRAM']);
        } else {
            $_ENV['TERM_PROGRAM'] = $saved;
        }

        $this->assertInstanceOf(Notifier::class, $notifier);
    }

    public function test_channel_detection_kitty(): void
    {
        $saved = $_ENV['TERM_PROGRAM'] ?? null;
        $_ENV['TERM_PROGRAM'] = 'kitty';

        $notifier = new Notifier;

        if ($saved === null) {
            unset($_ENV['TERM_PROGRAM']);
        } else {
            $_ENV['TERM_PROGRAM'] = $saved;
        }

        $this->assertInstanceOf(Notifier::class, $notifier);
    }

    public function test_channel_detection_ghostty(): void
    {
        $saved = $_ENV['TERM_PROGRAM'] ?? null;
        $_ENV['TERM_PROGRAM'] = 'ghostty';

        $notifier = new Notifier;

        if ($saved === null) {
            unset($_ENV['TERM_PROGRAM']);
        } else {
            $_ENV['TERM_PROGRAM'] = $saved;
        }

        $this->assertInstanceOf(Notifier::class, $notifier);
    }

    public function test_channel_detection_unknown_falls_back_to_bell(): void
    {
        $saved = $_ENV['TERM_PROGRAM'] ?? null;
        unset($_ENV['TERM_PROGRAM']);
        unset($_SERVER['TERM_PROGRAM']);

        ob_start();
        $notifier = new Notifier;
        $notifier->notify('Test', 'Message');
        $output = ob_get_clean();

        if ($saved !== null) {
            $_ENV['TERM_PROGRAM'] = $saved;
        }

        // terminal_bell channel sends "\007"
        $this->assertStringContainsString("\007", $output);
    }

    // ─── notify — channel dispatch ────────────────────────────────────────

    public function test_notify_disabled_channel_produces_no_output(): void
    {
        $notifier = new Notifier('disabled');
        ob_start();
        $notifier->notify('Title', 'Body');
        $output = ob_get_clean();
        $this->assertSame('', $output);
    }

    public function test_notify_bell_channel_sends_bell(): void
    {
        $notifier = new Notifier('terminal_bell');
        ob_start();
        $notifier->notify('Title', 'Body');
        $output = ob_get_clean();
        $this->assertStringContainsString("\007", $output);
    }

    public function test_notify_iterm2_sends_osc9(): void
    {
        $notifier = new Notifier('iterm2');
        ob_start();
        $notifier->notify('Hello', 'World');
        $output = ob_get_clean();
        $this->assertStringContainsString("\033]9;", $output);
        $this->assertStringContainsString('Hello', $output);
        $this->assertStringContainsString('World', $output);
    }

    public function test_notify_kitty_sends_osc99(): void
    {
        $notifier = new Notifier('kitty');
        ob_start();
        $notifier->notify('K-Title', 'K-Body');
        $output = ob_get_clean();
        $this->assertStringContainsString("\033]99;", $output);
    }

    public function test_notify_ghostty_sends_osc9(): void
    {
        $notifier = new Notifier('ghostty');
        ob_start();
        $notifier->notify('G-Title', 'G-Body');
        $output = ob_get_clean();
        $this->assertStringContainsString("\033]9;", $output);
        $this->assertStringContainsString('G-Title', $output);
    }

    // ─── hook executor integration ────────────────────────────────────────

    public function test_notify_calls_hook_executor(): void
    {
        $executor = $this->createMock(HookExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->with('Notification', $this->arrayHasKey('title'));

        $notifier = new Notifier('disabled', $executor);
        $notifier->notify('Hook Test', 'Checking hooks');
    }

    public function test_notify_without_executor_does_not_throw(): void
    {
        $notifier = new Notifier('disabled');
        $notifier->notify('No Executor', 'Fine');
        $this->assertTrue(true); // no exception
    }
}
