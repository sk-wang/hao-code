<?php

namespace App\Services\Notification;

use App\Services\Hooks\HookExecutor;

/**
 * Sends desktop notifications via terminal escape sequences.
 * Supports iTerm2, Kitty, Ghostty, and generic terminal bell.
 *
 * Notification hooks are fired before the terminal notification is sent,
 * matching claude-code's notifier.ts behaviour.
 */
class Notifier
{
    private string $channel;

    public function __construct(
        ?string $channel = null,
        private readonly ?HookExecutor $hookExecutor = null,
    ) {
        $this->channel = $channel ?? $this->detectChannel();
    }

    /**
     * Send a desktop notification.
     */
    public function notify(string $title, string $message, string $type = 'info'): void
    {
        // Run notification hooks first
        $this->executeNotificationHooks($title, $message, $type);

        match ($this->channel) {
            'iterm2' => $this->notifyITerm2($title, $message),
            'kitty' => $this->notifyKitty($title, $message),
            'ghostty' => $this->notifyGhostty($title, $message),
            'terminal_bell' => $this->ringBell(),
            'disabled' => null,
            default => $this->ringBell(),
        };
    }

    /**
     * Detect the best notification channel for the current terminal.
     */
    private function detectChannel(): string
    {
        $termProgram = $_ENV['TERM_PROGRAM'] ?? $_SERVER['TERM_PROGRAM'] ?? '';

        return match (true) {
            str_contains($termProgram, 'iTerm') => 'iterm2',
            str_contains($termProgram, 'kitty') => 'kitty',
            str_contains($termProgram, 'ghostty') => 'ghostty',
            str_contains($termProgram, 'Apple_Terminal') => 'terminal_bell',
            str_contains($termProgram, 'vscode') => 'terminal_bell',
            default => 'terminal_bell',
        };
    }

    /**
     * iTerm2 OSC 9 notification.
     */
    private function notifyITerm2(string $title, string $message): void
    {
        // iTerm2 supports OSC 9 for notifications
        $payload = "{$title}: {$message}";
        echo "\033]9;{$payload}\007";
    }

    /**
     * Kitty notification via OSC 99.
     */
    private function notifyKitty(string $title, string $message): void
    {
        $id = random_int(1000, 9999);
        $encodedTitle = base64_encode($title);
        $encodedBody = base64_encode($message);
        echo "\033]99;i={$id}:p=title;{$encodedTitle}\033\\";
        echo "\033]99;i={$id}:p=body;{$encodedBody}\033\\";
        echo "\033]99;i={$id}:p=actions;\033\\";
    }

    /**
     * Ghostty notification via OSC 9.
     */
    private function notifyGhostty(string $title, string $message): void
    {
        $payload = "{$title}: {$message}";
        echo "\033]9;{$payload}\007";
    }

    /**
     * Simple terminal bell.
     */
    private function ringBell(): void
    {
        echo "\007";
    }

    /**
     * Execute Notification hooks before sending the terminal notification.
     * Mirrors claude-code's notifier.ts hook execution at line 25.
     */
    private function executeNotificationHooks(string $title, string $message, string $type): void
    {
        $this->hookExecutor?->execute('Notification', [
            'title'   => $title,
            'message' => $message,
            'type'    => $type,
        ]);
    }
}
