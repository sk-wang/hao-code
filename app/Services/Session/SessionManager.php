<?php

namespace App\Services\Session;

class SessionManager
{
    private string $sessionId;
    private string $sessionPath;

    public function __construct()
    {
        $this->sessionId = date('Y-m-d_His') . '_' . bin2hex(random_bytes(4));
        $this->sessionPath = config('haocode.session_path', storage_path('app/haocode/sessions'));
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Record an entry to the session transcript (JSONL format).
     */
    public function recordEntry(array $entry): void
    {
        if (!is_dir($this->sessionPath)) {
            mkdir($this->sessionPath, 0755, true);
        }

        $line = json_encode(array_merge(['timestamp' => date('c'), 'session_id' => $this->sessionId], $entry),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        file_put_contents($this->getFilePath(), $line, FILE_APPEND);
    }

    /**
     * Record a complete turn (user message → assistant response → tool results).
     */
    public function recordTurn(array $assistantMessage, array $toolResults): void
    {
        $this->recordEntry([
            'type' => 'assistant_turn',
            'message' => $assistantMessage,
            'tool_results' => $toolResults,
        ]);
    }

    public function recordUserMessage(string $text): void
    {
        $this->recordEntry([
            'type' => 'user_message',
            'content' => $text,
        ]);
    }

    /**
     * Load a previous session from transcript.
     */
    public function loadSession(string $sessionId): array
    {
        $pattern = $this->sessionPath . '/' . $sessionId . '_*.jsonl';
        $files = glob($pattern);

        if (empty($files)) {
            return [];
        }

        $entries = [];
        foreach (file($files[0]) as $line) {
            if (trim($line)) {
                $entries[] = json_decode($line, true);
            }
        }

        return $entries;
    }

    private function getFilePath(): string
    {
        return $this->sessionPath . '/' . $this->sessionId . '.jsonl';
    }
}
